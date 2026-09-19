FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Render web services expect the app to listen on port 10000.
RUN sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/

# Keep the large OCR models inside the deployed image.
# They are downloaded once while Render builds the image, not on every OCR request.
RUN mkdir -p /var/www/html/models \
    && curl -L --fail --retry 3 --retry-delay 2 \
      -o /var/www/html/models/det.tar \
      "https://huggingface.co/LunarOilRig/paddleocr-onnx/resolve/main/PP-OCRv6_medium_det_onnx_infer.tar" \
    && curl -L --fail --retry 3 --retry-delay 2 \
      -o /var/www/html/models/rec.tar \
      "https://huggingface.co/LunarOilRig/paddleocr-onnx/resolve/main/PP-OCRv6_medium_rec_onnx_infer.tar"

EXPOSE 10000

CMD ["apache2-foreground"]
