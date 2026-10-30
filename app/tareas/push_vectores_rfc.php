<?php
/**
 * Verifica el cifrado Web Push (app/servicios/cuenta/push.php) contra los vectores de prueba del RFC 8291,
 * apéndice A, y la firma VAPID (ES256, DER → JOSE) contra openssl_verify.
 * Solo desde la línea de comandos:  C:/xampp/php/php.exe app/tareas/push_vectores_rfc.php
 * No toca la base de datos ni la red. Sale con código 1 si algo no coincide.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../servicios/cuenta/push.php';

$fallas = 0;
function comprobar(string $nombre, bool $ok): void
{
    global $fallas;
    echo ($ok ? '  ok    ' : '  FALLA ') . $nombre . "\n";
    if (!$ok) {
        $fallas++;
    }
}

// RFC 8291, apéndice A
$v = [
    'texto'        => 'V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24',
    'as_privada'   => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
    'as_publica'   => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
    'ua_privada'   => 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94',
    'ua_publica'   => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
    'secreto'      => 'kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs',
    'auth'         => 'BTBZMqHH6r4Tts7J_aSIgg',
    'sal'          => 'DGv6ra1nlYgDCS1FRnbzlw',
    'prk_clave'    => 'Snr3JMxaHVDXHWJn5wdC52WjpCtd2EIEGBykDcZW32k',
    'ikm'          => 'S4lYMb_L0FxCeq0WhDx813KgSYqU26kOyzWUdsXYyrg',
    'prk'          => '09_eUZGrsvxChDCGRCdkLiDXrReGOEVeSCdCcPBSJSc',
    'cek'          => 'oIhVW04MRdy2XN9CiKLxTg',
    'nonce'        => '4h_95klXJ5E_qnoN',
    'resultado'    => 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
];
$b = array_map('desdeB64u', $v);

echo "RFC 8291, apéndice A\n";
$asPem = pemPrivadaEc($b['as_privada'], $b['as_publica']);
$uaPem = pemPrivadaEc($b['ua_privada'], $b['ua_publica']);
comprobar('llaves de prueba válidas en P-256', (bool) openssl_pkey_get_private($asPem) && (bool) llavePublicaEc($b['ua_publica']));

$secreto = openssl_pkey_derive(llavePublicaEc($b['ua_publica']), openssl_pkey_get_private($asPem), 32);
comprobar('secreto ECDH (servidor)', $secreto === $b['secreto']);
comprobar('secreto ECDH (navegador)', openssl_pkey_derive(llavePublicaEc($b['as_publica']), openssl_pkey_get_private($uaPem), 32) === $b['secreto']);
comprobar('PRK de la llave', hash_hmac('sha256', $secreto, $b['auth'], true) === $b['prk_clave']);
$ikm = hash_hkdf('sha256', $secreto, 32, "WebPush: info\0" . $b['ua_publica'] . $b['as_publica'], $b['auth']);
comprobar('IKM', $ikm === $b['ikm']);
comprobar('PRK', hash_hmac('sha256', $ikm, $b['sal'], true) === $b['prk']);
comprobar('CEK', hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $b['sal']) === $b['cek']);
comprobar('NONCE', hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $b['sal']) === $b['nonce']);

$cuerpo = cifrarCargaPush($b['texto'], $b['ua_publica'], $b['auth'], 0,
    ['privada' => $asPem, 'publica' => $b['as_publica'], 'sal' => $b['sal']]);
comprobar('encabezado + texto cifrado idénticos al RFC (cifrarCargaPush)', hash_equals($b['resultado'], $cuerpo));

/** Descifrado del lado del navegador (para comprobar mensajes con llave efímera y relleno). */
function descifrarComoNavegador(string $cuerpo, string $uaPem, string $uaPublica, string $auth): ?string
{
    $sal = substr($cuerpo, 0, 16);
    $rs = unpack('N', substr($cuerpo, 16, 4))[1];
    $idlen = ord($cuerpo[20]);
    $asPublica = substr($cuerpo, 21, $idlen);
    $registro = substr($cuerpo, 21 + $idlen);
    if ($rs !== 4096 || $idlen !== 65 || strlen($registro) > $rs) {
        return null;
    }
    $secreto = openssl_pkey_derive(llavePublicaEc($asPublica), openssl_pkey_get_private($uaPem), 32);
    $ikm = hash_hkdf('sha256', $secreto, 32, "WebPush: info\0" . $uaPublica . $asPublica, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
    $claro = openssl_decrypt(substr($registro, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($registro, -16));
    if ($claro === false) {
        return null;
    }
    $claro = rtrim($claro, "\0");
    return substr($claro, -1) === "\x02" ? substr($claro, 0, -1) : null;
}

comprobar('el navegador descifra el vector del RFC', descifrarComoNavegador($b['resultado'], $uaPem, $b['ua_publica'], $b['auth']) === $b['texto']);

echo "\nIda y vuelta con llave efímera y relleno\n";
$ua = nuevaLlaveEc();
$uaPemNueva = pemPrivadaEc($ua['d'], $ua['publica']);
$auth = random_bytes(16);
$texto = '{"titulo":"Expedientes Clínicos","cuerpo":"Tienes una cita en 30 minutos"}';
$c1 = cifrarCargaPush($texto, $ua['publica'], $auth, 200);
$c2 = cifrarCargaPush($texto, $ua['publica'], $auth, 200);
comprobar('descifra con relleno', descifrarComoNavegador($c1, $uaPemNueva, $ua['publica'], $auth) === $texto);
comprobar('cada mensaje usa sal y llave efímera distintas', substr($c1, 0, 86) !== substr($c2, 0, 86));
$alterado = $c1;
$alterado[strlen($alterado) - 1] = chr(ord($alterado[strlen($alterado) - 1]) ^ 1);
comprobar('un byte alterado no descifra (etiqueta GCM)', descifrarComoNavegador($alterado, $uaPemNueva, $ua['publica'], $auth) === null);
comprobar('rechaza un p256dh fuera de la curva', llavePublicaEc("\x04" . str_repeat("\x01", 64)) === null);

echo "\nVAPID (ES256, firma DER → JOSE R||S)\n";
$par = nuevaLlaveEc();
$pem = pemPrivadaEc($par['d'], $par['publica']);
$ok = true;
for ($i = 0; $i < 200 && $ok; $i++) {   // varias firmas: cubre r o s con byte inicial alto o con ceros a la izquierda
    $dato = random_bytes(40);
    openssl_sign($dato, $der, openssl_pkey_get_private($pem), OPENSSL_ALGO_SHA256);
    $jose = firmaDerAJose($der);
    $r = ltrim(substr($jose, 0, 32), "\0");
    $s = ltrim(substr($jose, 32), "\0");
    $entero = fn ($n) => "\x02" . chr(strlen(ord($n[0]) > 0x7f ? "\0$n" : $n)) . (ord($n[0]) > 0x7f ? "\0$n" : $n);
    $rehecho = $entero($r) . $entero($s);
    $ok = strlen($jose) === 64 && openssl_verify($dato, "\x30" . chr(strlen($rehecho)) . $rehecho, pemPublicaEc($par['publica']), OPENSSL_ALGO_SHA256) === 1;
}
comprobar('200 firmas convertidas a R||S de 64 bytes y verificadas', $ok);
comprobar('rechaza una firma DER malformada', firmaDerAJose("\x30\x05\x02\x01\x01\x02\x00") === null);

echo "\nValidación de endpoints (SSRF)\n";
foreach ([
    ['https://fcm.googleapis.com/fcm/send/abc', true],
    ['https://updates.push.services.mozilla.com/wpush/v2/abc', true],
    ['https://web.push.apple.com/QGx', true],
    ['https://wns2-by3p.notify.windows.com/w/?token=abc', true],
    ['http://fcm.googleapis.com/fcm/send/abc', false],
    ['https://fcm.googleapis.com:8443/fcm/send/abc', false],
    ['https://127.0.0.1/fcm/send/abc', false],
    ['https://[::1]/x', false],
    ['https://192.168.1.10/x', false],
    ['https://user@fcm.googleapis.com/fcm/send/abc', false],
    ['https://fcm.googleapis.com.evil.example/x', false],
    ['https://evilnotify.windows.com/x', false],
    ['https://localhost/x', false],
    ['https://fcm.googleapis.com/' . str_repeat('a', 1100), false],
    ["https://fcm.googleapis.com/fcm/send/a b", false],
    ['https://fcm.googleapis.com/x#frag', false],
] as [$url, $esperado]) {
    comprobar(($esperado ? 'acepta ' : 'rechaza ') . mb_strimwidth($url, 0, 70, '…'), endpointPushAceptable($url) === $esperado);
}

echo $fallas === 0 ? "\nTodo coincide.\n" : "\n$fallas comprobaciones fallaron.\n";
exit($fallas === 0 ? 0 : 1);
