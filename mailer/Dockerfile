FROM python:3.13-slim

RUN useradd -u 10001 -m mailer && mkdir -p /data && chown mailer:mailer /data

WORKDIR /app
COPY mailer.py /app/mailer.py

USER mailer

CMD ["python", "-u", "/app/mailer.py"]
