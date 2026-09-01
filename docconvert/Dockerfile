FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /srv

# lxml and pandas ship manylinux wheels, so no compiler tool-chain is needed.
COPY requirements.txt /srv/requirements.txt
RUN pip install --no-cache-dir -r /srv/requirements.txt

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
