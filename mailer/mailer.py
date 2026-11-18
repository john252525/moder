#!/usr/bin/env python3
"""moder mailer — забирает approved email-записи и отправляет через SMTP.

Контракт: POST /api/devops/claim {types:["email"]} -> отправка -> applied/failed.

Безопасность: по умолчанию MAILER_DRY_RUN=1 (только логирует, не шлёт).
Гибкость: дневной лимит, режимы batch/spread, окно отправки, пауза между письмами,
отдельные SMTP-креды на каждый from-адрес.
"""
from __future__ import annotations

import json
import os
import random
import smtplib
import ssl
import sys
import time
from datetime import datetime, timedelta, timezone
from email.message import EmailMessage
from email.utils import formataddr, make_msgid
from pathlib import Path
from typing import Any

try:
    from zoneinfo import ZoneInfo
except ImportError:  # pragma: no cover
    ZoneInfo = None  # type: ignore


# --------------------------------------------------------------------------- #
# Конфигурация (всё через ENV; см. .env.example)
# --------------------------------------------------------------------------- #

def env(key: str, default: str = "") -> str:
    return (os.environ.get(key) or default).strip()


def env_int(key: str, default: int) -> int:
    try:
        return int(env(key) or default)
    except ValueError:
        return default


def env_bool(key: str, default: bool) -> bool:
    raw = env(key).lower()
    if not raw:
        return default
    return raw in ("1", "true", "yes", "on", "y")


CFG = {
    "base": env("MODER_BASE", "http://127.0.0.1:39010").rstrip("/"),
    "token": env("MODER_TOKEN_DEVOPS"),
    "poll_interval": env_int("MAILER_POLL_INTERVAL", 60),
    "batch_size": env_int("MAILER_BATCH_SIZE", 5),
    "daily_limit": env_int("MAILER_DAILY_LIMIT", 5),
    "mode": env("MAILER_MODE", "batch").lower(),          # batch | spread
    "window": env("MAILER_WINDOW", "09:00-18:00"),          # для spread
    "tz": env("MAILER_TZ", "Europe/Moscow"),
    "min_gap": env_int("MAILER_MIN_GAP_SECONDS", 0),
    "dry_run": env_bool("MAILER_DRY_RUN", True),
    "state_file": Path(env("MAILER_STATE_FILE", "/app/state.json")),
    "smtp_host": env("MAILER_SMTP_HOST", "smtp.mail.ru"),
    "smtp_port": env_int("MAILER_SMTP_PORT", 465),
    "smtp_user": env("MAILER_SMTP_USER"),
    "smtp_pass": env("MAILER_SMTP_PASS"),
    "smtp_from_name": env("MAILER_FROM_NAME", ""),
    "smtp_timeout": env_int("MAILER_SMTP_TIMEOUT", 30),
    "log_level": env("MAILER_LOG_LEVEL", "info").lower(),
}


def log(msg: str, level: str = "info") -> None:
    order = {"debug": 0, "info": 1, "warn": 2, "error": 3}
    if order.get(level, 1) < order.get(CFG["log_level"], 1):
        return
    ts = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    print(f"{ts} [{level.upper()}] {msg}", flush=True)


# --------------------------------------------------------------------------- #
# SMTP-аккаунты: отдельные креды на каждый from-адрес.
#
# Формат MAILER_ACCOUNTS (JSON) — приоритетный, гибкий:
#   {"sales@a.ru": {"user":"sales@a.ru","pass":"...","host":"smtp.mail.ru","port":465,
#                   "from_name":"Sales"}, ...}
# Фолбэк: один аккаунт из MAILER_SMTP_USER/MAILER_SMTP_PASS.
# --------------------------------------------------------------------------- #

def load_accounts() -> dict[str, dict[str, Any]]:
    raw = env("MAILER_ACCOUNTS")
    accounts: dict[str, dict[str, Any]] = {}
    if raw:
        try:
            data = json.loads(raw)
            if isinstance(data, dict):
                for addr, c in data.items():
                    if not isinstance(c, dict):
                        continue
                    accounts[addr.lower()] = {
                        "user": str(c.get("user") or addr),
                        "pass": str(c.get("pass") or ""),
                        "host": str(c.get("host") or CFG["smtp_host"]),
                        "port": int(c.get("port") or CFG["smtp_port"]),
                        "from_name": str(c.get("from_name") or CFG["smtp_from_name"]),
                        "ssl": bool(c.get("ssl", True)),
                    }
        except json.JSONDecodeError as exc:
            log(f"MAILER_ACCOUNTS is not valid JSON: {exc}", "error")
    if not accounts and CFG["smtp_user"] and CFG["smtp_pass"]:
        accounts[CFG["smtp_user"].lower()] = {
            "user": CFG["smtp_user"],
            "pass": CFG["smtp_pass"],
            "host": CFG["smtp_host"],
            "port": CFG["smtp_port"],
            "from_name": CFG["smtp_from_name"],
            "ssl": CFG["smtp_port"] == 465,
        }
    return accounts


ACCOUNTS = load_accounts()


# --------------------------------------------------------------------------- #
# API-клиент moder (только stdlib).
# --------------------------------------------------------------------------- #

def api(method: str, path: str, body: dict[str, Any] | None = None) -> dict[str, Any]:
    import urllib.error
    import urllib.request

    url = CFG["base"] + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", f"Bearer {CFG['token']}")
    if data is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return json.loads(resp.read().decode() or "{}")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode(errors="replace")[:500]
        raise RuntimeError(f"HTTP {exc.code} on {method} {path}: {detail}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"network error on {method} {path}: {exc}") from exc


def claim_emails(limit: int) -> list[dict[str, Any]]:
    res = api("POST", "/api/devops/claim", {"types": ["email"], "limit": limit})
    return list(res.get("items") or [])


def peek_emails(limit: int) -> list[dict[str, Any]]:
    """Только чтение (для dry-run): не захватывает, не трогает apply_attempts."""
    res = api("GET", f"/api/queue?status=approved&type=email&limit={limit}&sort=oldest")
    items = list(res.get("items") or [])
    # список отдаётся без payload — дотягиваем детали по каждой записи
    detailed = []
    for it in items:
        try:
            detailed.append(api("GET", f"/api/queue/{it['id']}"))
        except RuntimeError:
            detailed.append(it)
    return detailed


def mark_applied(item_id: str, comment: str) -> None:
    api("POST", f"/api/devops/{item_id}/applied", {"comment": comment})


def mark_failed(item_id: str, error: str) -> None:
    api("POST", f"/api/devops/{item_id}/failed", {"error": error})


# --------------------------------------------------------------------------- #
# Сборка письма из payload (email-тип).
# --------------------------------------------------------------------------- #

def build_message(item: dict[str, Any]) -> tuple[EmailMessage, dict[str, Any]]:
    payload = item.get("payload") or {}
    sender = str(payload.get("from") or "").strip().lower()
    if not sender:
        raise ValueError("payload.from is empty (mailer needs it to pick SMTP account)")
    account = ACCOUNTS.get(sender)
    if account is None:
        known = ", ".join(sorted(ACCOUNTS)) or "<none configured>"
        raise ValueError(f"no SMTP account for from='{sender}' (configured: {known})")

    to = payload.get("to") or []
    if isinstance(to, str):
        to = [to]
    to = [str(x).strip() for x in to if str(x).strip()]
    if not to:
        raise ValueError("payload.to is empty")

    msg = EmailMessage()
    subject = str(payload.get("subject") or item.get("title") or "(no subject)")
    msg["Subject"] = subject
    msg["From"] = formataddr((account["from_name"], sender)) if account["from_name"] else sender
    msg["To"] = ", ".join(to)
    cc = payload.get("cc") or []
    if isinstance(cc, str):
        cc = [cc]
    cc = [str(x).strip() for x in cc if str(x).strip()]
    if cc:
        msg["Cc"] = ", ".join(cc)
    msg["Message-ID"] = make_msgid(domain=sender.split("@")[-1])

    body = str(payload.get("body") or "")
    html = str(payload.get("html") or "")
    if html:
        msg.set_content(body or " ")
        msg.add_alternative(html, subtype="html")
    else:
        msg.set_content(body or " ")

    return msg, account


def smtp_send(msg: EmailMessage, account: dict[str, Any]) -> None:
    host, port = account["host"], int(account["port"])
    timeout = CFG["smtp_timeout"]
    context = ssl.create_default_context()
    if account.get("ssl", True):
        with smtplib.SMTP_SSL(host, port, timeout=timeout, context=context) as s:
            s.login(account["user"], account["pass"])
            s.send_message(msg)
    else:
        with smtplib.SMTP(host, port, timeout=timeout) as s:
            s.ehlo()
            s.starttls(context=context)
            s.ehlo()
            s.login(account["user"], account["pass"])
            s.send_message(msg)


# --------------------------------------------------------------------------- #
# Дневной бюджет и окно отправки.
#
# MAILER_MODE=batch  — за прогон не больше batch_size за раз, потом пауза poll_interval.
# MAILER_MODE=spread — не больше daily_limit за сутки, отправка только в окне
#                      MAILER_WINDOW (например 09:00-18:00), между письмами пауза,
#                      равномерно распределённая по окну.
# --------------------------------------------------------------------------- #

def load_state() -> dict[str, Any]:
    try:
        data = json.loads(CFG["state_file"].read_text())
        if isinstance(data, dict):
            return data
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        pass
    return {}


def save_state(state: dict[str, Any]) -> None:
    try:
        CFG["state_file"].parent.mkdir(parents=True, exist_ok=True)
        CFG["state_file"].write_text(json.dumps(state, ensure_ascii=False))
    except OSError as exc:
        log(f"cannot write state file {CFG['state_file']}: {exc}", "warn")


def today(tz_name: str) -> str:
    if ZoneInfo is None:
        return datetime.now(timezone.utc).date().isoformat()
    return datetime.now(ZoneInfo(tz_name)).date().isoformat()


def budget_left(state: dict[str, Any]) -> int:
    day = today(CFG["tz"])
    if state.get("day") != day:
        state["day"] = day
        state["sent_today"] = 0
        save_state(state)
    return max(0, CFG["daily_limit"] - int(state.get("sent_today", 0)))


def parse_window(raw: str) -> tuple[int, int]:
    try:
        start_s, end_s = raw.split("-")
        sh, sm = [int(x) for x in start_s.strip().split(":")]
        eh, em = [int(x) for x in end_s.strip().split(":")]
        return sh * 60 + sm, eh * 60 + em
    except (ValueError, IndexError):
        log(f"bad MAILER_WINDOW='{raw}', falling back to 00:00-23:59", "warn")
        return 0, 23 * 60 + 59


def in_window() -> bool:
    if CFG["mode"] != "spread":
        return True
    start, end = parse_window(CFG["window"])
    now = datetime.now(ZoneInfo(CFG["tz"])) if ZoneInfo else datetime.now()
    cur = now.hour * 60 + now.minute
    return start <= cur <= end


def seconds_until_window() -> int:
    start, _ = parse_window(CFG["window"])
    now = datetime.now(ZoneInfo(CFG["tz"])) if ZoneInfo else datetime.now()
    cur = now.hour * 60 + now.minute
    if cur < start:
        return (start - cur) * 60
    return 60  # уже вне окна после конца — проверим через минуту


# --------------------------------------------------------------------------- #
# Основной цикл.
# --------------------------------------------------------------------------- #

def process_item(item: dict[str, Any]) -> bool:
    item_id = str(item.get("id"))
    payload = item.get("payload") or {}
    to = payload.get("to") or []
    subject = str(payload.get("subject") or "")[:80]
    if CFG["dry_run"]:
        log(f"DRY-RUN would send {item_id} to {to} subj={subject!r}", "info")
        return True
    try:
        msg, account = build_message(item)
        smtp_send(msg, account)
        return True
    except Exception as exc:  # noqa: BLE001 — любая ошибка отправки -> failed
        log(f"send failed {item_id}: {exc}", "error")
        try:
            mark_failed(item_id, f"SMTP: {exc}"[:900])
        except RuntimeError as api_exc:
            log(f"cannot report failure for {item_id}: {api_exc}", "error")
        return False


def run_once(state: dict[str, Any]) -> int:
    left = budget_left(state)
    if left <= 0:
        log(f"daily limit reached ({CFG['daily_limit']}), sleeping", "info")
        return 0

    limit = min(CFG["batch_size"], left)
    if CFG["mode"] == "spread":
        limit = min(limit, 1)  # в spread-режиме по одному

    if CFG["dry_run"]:
        items = peek_emails(limit)
        if not items:
            log("no approved emails (dry-run)", "debug")
            return 0
        log(f"DRY-RUN: would process {len(items)} approved email(s) (nothing changed)")
        for it in items:
            payload = it.get("payload") or {}
            log(f"DRY-RUN would send {it.get('id')} from={payload.get('from')} "
                f"to={payload.get('to')} subj={str(payload.get('subject') or '')[:60]!r}", "info")
        return 0

    items = claim_emails(limit)
    if not items:
        log("no approved emails", "debug")
        return 0

    log(f"claimed {len(items)} email(s)")
    sent = 0
    for item in items:
        ok = process_item(item)
        item_id = str(item.get("id"))
        if ok:
            try:
                mark_applied(item_id, "sent by mailer")
                state["sent_today"] = int(state.get("sent_today", 0)) + 1
                save_state(state)
                sent += 1
            except RuntimeError as exc:
                log(f"cannot mark applied {item_id}: {exc}", "error")
        if CFG["mode"] == "spread" and sent:
            break
        if CFG["min_gap"] > 0 and item is not items[-1]:
            gap = CFG["min_gap"]
            if CFG["mode"] == "spread":
                # распределяем остаток окна на оставшиеся письма (с рандомом)
                _, end = parse_window(CFG["window"])
                now = datetime.now(ZoneInfo(CFG["tz"])) if ZoneInfo else datetime.now()
                left_min = max(1, end - (now.hour * 60 + now.minute))
                gap = max(1, int(left_min * 60 / max(1, CFG["daily_limit"])))
                gap += random.randint(0, max(1, gap // 4))
            log(f"sleeping {gap}s before next email", "debug")
            time.sleep(gap)
    return sent


def main() -> int:
    log(f"mailer starting: base={CFG['base']} mode={CFG['mode']} daily={CFG['daily_limit']} "
        f"batch={CFG['batch_size']} window={CFG['window']} tz={CFG['tz']} dry_run={CFG['dry_run']}")
    if not CFG["token"]:
        log("MODER_TOKEN_DEVOPS is not set", "error")
        return 2
    if not CFG["dry_run"] and not ACCOUNTS:
        log("no SMTP accounts configured (MAILER_ACCOUNTS / MAILER_SMTP_USER), refusing to start", "error")
        return 2
    log(f"smtp accounts: {', '.join(sorted(ACCOUNTS)) or '<none>'}")

    state = load_state()
    while True:
        try:
            if not in_window():
                wait = min(seconds_until_window(), 900)
                log(f"outside send window {CFG['window']}, sleeping {wait}s")
                time.sleep(wait)
                continue
            sent = run_once(state)
            if CFG["mode"] == "spread" and sent == 0 and budget_left(state) > 0:
                time.sleep(min(300, CFG["poll_interval"]))
                continue
            sleep_for = CFG["poll_interval"]
            if CFG["mode"] == "batch" and sent > 0:
                sleep_for = max(60, CFG["poll_interval"])
            time.sleep(sleep_for)
        except KeyboardInterrupt:
            log("stopped by user")
            return 0
        except Exception as exc:  # noqa: BLE001 — цикл не должен падать
            log(f"loop error: {exc}", "error")
            time.sleep(30)


if __name__ == "__main__":
    sys.exit(main())
