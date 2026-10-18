<?php
// Mini marco de pruebas compartido por tests/api.php y tests/instalacion.php (PHP + curl, sin dependencias).

declare(strict_types=1);

$superadas = 0;
$fallidas = [];

final class Sesion {
  private string $cookies;
  // $cabeceras: cabeceras extra en todas las peticiones (por ejemplo, X-API-Key)
  public function __construct(private string $base, private array $cabeceras = []) { $this->cookies = tempnam(sys_get_temp_dir(), 'blush'); }

  // Devuelve [código HTTP, cuerpo decodificado (o texto si no es JSON)]
  public function pedir(string $metodo, string $recurso, array $parametros = [], ?array $cuerpo = null, bool $cabecera = true): array {
    $url = $this->base . '?' . http_build_query(['recurso' => $recurso] + $parametros);
    $c = curl_init($url);
    curl_setopt_array($c, [
      CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true,
      CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_COOKIEFILE => $this->cookies,
      CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $this->cabeceras, $cabecera && $metodo !== 'GET' && !$this->cabeceras ? ['X-Blush: 1'] : []),
    ]);
    if ($cuerpo !== null) curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    $texto = (string)curl_exec($c);
    $codigo = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $json = json_decode($texto, true);
    return [$codigo, is_array($json) ? $json : $texto];
  }

  public function get(string $r, array $p = []): array { return $this->pedir('GET', $r, $p); }
  public function post(string $r, ?array $c = null, array $p = []): array { return $this->pedir('POST', $r, $p, $c ?? []); }
  public function put(string $r, int $id, array $c): array { return $this->pedir('PUT', $r, ['id' => $id], $c); }
  public function delete(string $r, int $id): array { return $this->pedir('DELETE', $r, ['id' => $id]); }

  public function entrar(string $email, string $clave = 'blush2026'): array {
    return $this->post('entrar', ['email' => $email, 'clave' => $clave]);
  }
  // Datos de una respuesta correcta; si no lo es, la prueba falla con el error de la API
  public function datos(array $respuesta): mixed {
    [$codigo, $json] = $respuesta;
    if (!is_array($json) || empty($json['ok'])) throw new Exception("Respuesta $codigo inesperada: " . json_encode($json, JSON_UNESCAPED_UNICODE));
    return $json['datos'];
  }
}

function prueba(string $nombre, callable $cuerpo): void {
  global $superadas, $fallidas;
  try {
    $cuerpo();
    $superadas++;
    echo "  \033[32m✓\033[0m $nombre\n";
  } catch (Throwable $e) {
    $fallidas[] = $nombre;
    echo "  \033[31m✗ $nombre\033[0m\n      {$e->getMessage()}\n";
  }
}

function igual(mixed $esperado, mixed $real, string $que = ''): void {
  if ($esperado !== $real) throw new Exception(($que ? "$que: " : '') . 'se esperaba ' . var_export($esperado, true) . ' y se obtuvo ' . var_export($real, true));
}

function codigo(int $esperado, array $respuesta): array {
  igual($esperado, $respuesta[0], 'código HTTP (' . json_encode($respuesta[1], JSON_UNESCAPED_UNICODE) . ')');
  return $respuesta[1];
}


function resumen(): never {
  global $superadas, $fallidas;
  $total = $superadas + count($fallidas);
  echo "\n" . ($fallidas ? "\033[31m" : "\033[32m") . "$superadas de $total pruebas superadas\033[0m\n";
  exit($fallidas ? 1 : 0);
}
