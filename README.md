# GBEYAN OCR

Browser-side PP-OCRv6 Medium crew-list OCR for GBEYAN.

## Architecture

- PHP/Apache only serves the app and model files.
- OCR inference runs in the user's browser with WebGPU.
- PP-OCRv6 Medium DET/REC models are downloaded into the Docker image at Render build time.
- The browser loads the models from the same Render origin.
- PaddleOCR JS and SheetJS are currently loaded from jsDelivr.
- THY parser maps:
  - C/C -> CP
  - C/P -> FO
  - S/A -> CA
  - S/S -> CA
- Excel output uses the GBEYAN seven-column template and fills only Adı, Soyadı and Mürettebat Tipi (REF).

## Render

Create a Render Web Service from this repository and choose Docker, or deploy the included render.yaml Blueprint.

Health check: /health.php
