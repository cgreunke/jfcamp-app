# matching/Dockerfile
FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

# System-Dependencies minimal halten (curl für Healthcheck)
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Python-Dependencies
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

# App-Code
# (Falls dein Repo mehrere Dateien enthält, ist COPY . korrekt. Sonst gezielt nur matching_server.py kopieren.)
COPY . /app

# Service-Port
EXPOSE 5001

# Healthcheck (ruft /health auf Port 5001)
HEALTHCHECK --interval=30s --timeout=5s --retries=5 \
  CMD curl -fsS http://localhost:5001/health || exit 1

# Produktionstauglicher Start (2 Worker für Dev ausreichend)
CMD ["gunicorn", "-w", "2", "-b", "0.0.0.0:5001", "matching_server:app"]
