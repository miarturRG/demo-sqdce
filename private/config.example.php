<?php
// Copia este archivo como config.php (en esta misma carpeta private) y llena tus datos.
// No compartas config.php. Esta carpeta está bloqueada para el navegador por .htaccess.
return [
    // Tu API key de Claude (console.anthropic.com → API Keys). Empieza con sk-ant-
    'anthropic_api_key' => 'PEGA_AQUI_TU_API_KEY',

    // Modelo. Haiku es el más rápido para la demo en vivo.
    // Otras opciones: 'claude-sonnet-5-5' (más profundo, un poco más lento).
    'model' => 'claude-haiku-5-5',

    // Opcional. Si pones una clave aquí, la demo solo usa la IA cuando la abres como
    // https://tu-dominio.com/demo-sqdce/?k=ESA-CLAVE  (sin la clave, sale la respuesta precargada).
    'demo_key' => '',

    // Límites para proteger tu saldo de API.
    'max_por_ip_hora' => 30,
    'max_por_dia' => 300,
];
