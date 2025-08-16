# matching/Dockerfile
FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

# System-Dependencies minimal halten
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl ca-certificates && \
    rm -rf /var/lib/apt/lists/*

# Python-Dependencies zuerst cachen
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

# App-Code
COPY . /app

# Flask / Gunicorn auf Port 5000
EXPOSE 5000

# Healthcheck (ruft /health)
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD curl -fsS http://localhost:5000/health || exit 1

# Produktionstauglicher Start (2 Worker für Dev ausreichend)
CMD ["gunicorn", "-w", "2", "-b", "0.0.0.0:5000", "matching_server:app"]
