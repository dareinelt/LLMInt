FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DEFAULT_TIMEOUT=120 \
    PIP_RETRIES=10 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /srv

# lxml and pandas ship manylinux wheels, so no compiler tool-chain is needed.
# pip does not retry a read timeout in the middle of a wheel download, so the
# whole install is retried a few times to survive slow/flaky PyPI mirrors.
COPY requirements.txt /srv/requirements.txt
RUN for attempt in 1 2 3; do \
        pip install --no-cache-dir -r /srv/requirements.txt && exit 0; \
        echo "pip install failed (attempt $attempt), retrying in 10s..." >&2; \
        sleep 10; \
    done; \
    exit 1

COPY app /srv/app

# Run unprivileged; the cache directory is the only writable path required.
RUN useradd --system --uid 10001 --create-home docconvert \
    && mkdir -p /var/cache/docconvert \
    && chown -R docconvert:docconvert /var/cache/docconvert /srv
USER docconvert

ENV DOCCONVERT_CACHE_DIR=/var/cache/docconvert

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4).status == 200 else 1)"

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2"]
