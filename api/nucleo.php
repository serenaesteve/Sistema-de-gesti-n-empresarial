<?php
// Respuestas y errores comunes a la API y al instalador por terminal.

// Los errores previstos se lanzan como excepción: así una transacción abierta se deshace
// antes de responder (ver crud()).
final class ErrorApi extends Exception {
  public function __construct(string $mensaje, int $codigo, public readonly array $errores = []) {
    parent::__construct($mensaje, $codigo);
  }
}

function responder(mixed $datos, int $codigo = 200): never {
  http_response_code($codigo);
  echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function fallar(string $mensaje, int $codigo, array $errores = []): never {
  throw new ErrorApi($mensaje, $codigo, $errores);
}
