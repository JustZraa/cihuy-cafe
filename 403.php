<?php
declare(strict_types=1);

http_response_code(403);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#5C3A21">
    <title>Akses Ditolak | CozyCafe</title>
    <style>
        :root{color-scheme:light;--coffee:#5C3A21;--cream:#F5EFE6;--warm:#FAF8F5;--ink:#2C1E16}
        *{box-sizing:border-box}body{display:grid;min-height:100vh;margin:0;padding:1.5rem;place-items:center;background:var(--warm);color:var(--ink);font:16px/1.6 Arial,sans-serif}
        main{width:min(100%,34rem);padding:clamp(1.5rem,5vw,3rem);border:1px solid var(--cream);border-radius:16px;background:#fff;box-shadow:0 8px 24px #2c1e1614;text-align:center}
        .brand{color:var(--coffee);font-weight:700;letter-spacing:.04em}.code{margin:1.5rem 0 0;color:var(--coffee);font:700 clamp(3rem,12vw,5rem)/1 Georgia,serif}
        h1{margin:.75rem 0;color:var(--coffee);font:700 1.5rem Georgia,serif}p{margin:0 0 1.5rem}a{display:inline-block;padding:.7rem 1rem;border-radius:10px;background:var(--coffee);color:#fff;text-decoration:none}
    </style>
</head>
<body>
<main>
    <div class="brand">CozyCafe</div>
    <p class="code">403</p>
    <h1>Akses Ditolak</h1>
    <p>Maaf, Anda tidak memiliki izin untuk membuka halaman ini.</p>
    <a href="./">Kembali ke CozyCafe</a>
</main>
</body>
</html>
