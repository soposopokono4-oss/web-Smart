<?php
// Salin file ini menjadi config.php, lalu isi kredensial database dan API AI.
// Jangan unggah config.php ke repositori publik.
return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'smartstudy',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
  ],
  // Gunakan penyedia API yang kompatibel dengan format Chat Completions.
  // API key hanya disimpan di server, jangan pernah ditaruh di app.js.
  'ai' => [
    'api_key' => 'ISI_API_KEY_DI_SERVER',
    'endpoint' => 'https://api.openai.com/v1/chat/completions',
    'model' => 'gpt-4o-mini',
  ],
];
