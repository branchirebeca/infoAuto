<?php

ini_set('max_execution_time', 1000); // Aumenta el tiempo máximo de ejecución a 600 segundos (10 minutos)

function getAuthToken()
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.infoauto.com.ar/cars/auth/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => "", // Cuerpo de solicitud vacío
        CURLOPT_HTTPHEADER => array(
            'Content-Length: 0', // Longitud del cuerpo de la solicitud: 0
            'Authorization: Basic token',
            'Cookie: ...'
        ),
    ));

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        echo 'Error al realizar la solicitud: ' . curl_error($curl);
    }

    curl_close($curl);

    // Verificar si la respuesta es válida
    $responseArray = json_decode($response, true);

    if ($responseArray === null && json_last_error() !== JSON_ERROR_NONE) {
        echo "Error al decodificar la respuesta JSON.";
        return null;
    }

    if (isset($responseArray['access_token'])) {
        return $responseArray['access_token'];
    } else {
        echo "Error: No se pudo obtener el token de acceso. Respuesta recibida: " . $response;
        return null;
    }
}

// Función para obtener información sobre los modelos de autos
function traerInfo($access_token, $modelo)
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.infoauto.com.ar/cars/pub/search/?query_mode=matching&page=1&query_string=$modelo&page_size=99",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $access_token,
            'Cookie: session=.eJyrVspMSc0rySyp1EssLcmIL6ksSFWyyivNydFByGSmQIRqAZu4EWc.ZlB-wQ.ELljUg7UhRZJ2t3Ygn9FuWYvHQU'
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return $response;
}

function grabar($codia, $descripcion, $precio_desde, $precio_hasta) {
    // Datos de conexión
    $host = "mysql-prod01qa.decreditoslabs.com";
    $usuario = "app-agencias2";
    $contrasena = 'Decre*3202';
    $base_de_datos = "Config_Agencias2";

    // Conexión a la base de datos
    $conexion = mysqli_connect($host, $usuario, $contrasena, $base_de_datos);

    // Verificar la conexión
    if (!$conexion) {
        die("Error de conexión: " . mysqli_connect_error());
    }

    // Preparar la consulta SQL de verificación de existencia
    $sql_existencia = "SELECT codia FROM vehiculos_deshabilitados WHERE codia = ?";
    $stmt_existencia = mysqli_prepare($conexion, $sql_existencia);
    mysqli_stmt_bind_param($stmt_existencia, "i", $codia);
    mysqli_stmt_execute($stmt_existencia);
    mysqli_stmt_store_result($stmt_existencia);
    $filas_existencia = mysqli_stmt_num_rows($stmt_existencia);
    
    // Si el modelo de auto no existe, insertar los datos
    if ($filas_existencia == 0) {
        // Preparar la consulta SQL de inserción
        $sql = "INSERT INTO vehiculos_deshabilitados (codia, vehiculo, fecha_desde, fecha_hasta) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conexion, $sql);
        mysqli_stmt_bind_param($stmt, "isss", $codia, $descripcion, $precio_desde, $precio_hasta);

        // Ejecutar la consulta preparada
        $resultado = mysqli_stmt_execute($stmt);

        // Verificar si la ejecución fue exitosa
        if ($resultado) {
            echo "Se insertó correctamente.";
        } else {
            echo "Error al insertar: " . mysqli_error($conexion);
        }

        // Cerrar la consulta preparada de inserción
        mysqli_stmt_close($stmt);
    } else {
        echo "El modelo de auto con el código $codia ya existe en la base de datos. Se omite la inserción.";
    }

    // Cerrar la consulta de verificación de existencia y la conexión
    mysqli_stmt_close($stmt_existencia);
    mysqli_close($conexion);
}

$token = getAuthToken();
if (!$token) {
    die("Error: No se pudo obtener el token de acceso.");
}

$modelos = ["jumpy", "logan", "siena", "gol country", "corsa", "trafic", "sprinter", "master", "transit", "ducato", "jumper", "expert", "boxer", "vito"];

$batchSize = 1; // Define el tamaño del lote (lote más pequeño)
$totalModelos = count($modelos);

for ($i = 0; $i < $totalModelos; $i += $batchSize) {
    $batch = array_slice($modelos, $i, $batchSize);
    
    foreach ($batch as $modelo) {
        $respuesta = traerInfo($token, $modelo);

        if (empty($respuesta)) {
            echo "Error: No se recibió una respuesta válida del servidor.";
            continue;
        }

        $respuesta_decodificada = json_decode($respuesta, true);

        if ($respuesta_decodificada === null || !is_array($respuesta_decodificada)) {
            echo "Error: No se pudieron obtener los datos de los modelos.";
            continue;
        }

        foreach ($respuesta_decodificada as $modelo) {
            $codia = $modelo['codia'];
            $descripcion = $modelo['brand']['name'] . " " . $modelo['description'];
            $precio_desde = isset($modelo['prices_from']) ? $modelo['prices_from'] : 'No disponible';
            $precio_hasta = isset($modelo['prices_to']) ? $modelo['prices_to'] : 'No disponible';

            grabar($codia, $descripcion, $precio_desde, $precio_hasta);
        }
    }

    // Imprimir un mensaje de progreso después de cada lote
    echo "Lote " . ($i + 1) . " de " . $totalModelos . " procesado.\n";

    // Pausar brevemente para evitar sobrecargar el servidor
    sleep(2); // Aumenta el tiempo de pausa a 2 segundos
}
?>
