# Paymos para OpenCart

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Extensión oficial de pagos de Paymos para OpenCart 4.0.2.0 y versiones posteriores. El comprador
confirma el pedido, llega a la página de pago alojada de Paymos y paga con el token y la red que
tenga habilitados tu proyecto — Tron, Ethereum, BSC, Solana y el resto del conjunto compatible.
A partir de ahí tu pedido de OpenCart avanza solo, impulsado por un callback firmado en lugar del
regreso del navegador. La factura se emite en la moneda del pedido y el tipo de cambio se bloquea
en el momento en que paga el comprador.

Lo más importante que conviene saber antes que nada: tú decides qué estado de *tus* pedidos
escribe cada fase del pago. La extensión incluye valores predeterminados, pero los cinco mapeos
son desplegables cargados desde tu propia lista de estados, de modo que Paymos encaja en el flujo
que ya usa tu tienda en lugar de imponer uno nuevo.

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Vídeo demostrativo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- OpenCart 4.0.2.0 o posterior; la extensión no se instalará en 4.0.0.0–4.0.1.1;
- PHP 8.0 o posterior con las extensiones `curl`, `hash`, `json` y `openssl`;
- una URL de tienda (Store URL) en HTTPS accesible desde internet público;
- una cuenta de Paymos con el proyecto al que debe facturar esta tienda ya abierto en el panel.

El SDK de Paymos para PHP viene incluido dentro del paquete — no hace falta instalar nada con
Composer. Los textos del panel y de la tienda se entregan en inglés, ruso, alemán, español, turco
y chino simplificado.

## Instalación y conexión

1. Descarga el último paquete desde [GitHub Releases](https://github.com/paymos-labs/opencart/releases/latest).
2. Sube `paymos.ocmod.zip` en **Extensions → Installer**.
3. Abre **Extensions → Extensions**, elige **Payments** e instala Paymos.
4. Edítalo y abre en el panel de Paymos el proyecto correspondiente; ese proyecto activo se usará automáticamente.
5. Haz clic en **Connect Paymos** en los ajustes de la extensión.
6. Aprueba en Paymos la URL de la tienda y el proyecto actual que se muestran.

**No renombres el archivo.** OpenCart deduce el directorio de la extensión a partir del nombre del
archivo subido, y todos los espacios de nombres de los controladores del paquete se construyen a
partir de ese directorio. Un zip renombrado se instala en una carpeta que nada en su interior
puede localizar, y la extensión falla al cargarse en silencio.

La versión publicada es un único archivo para todos los comercios y no guarda credencial alguna:
ni clave API ni secreto, ni ID de proyecto, ni secreto de webhook, ni token OAuth ni código de
dispositivo. Nada queda activo hasta completar el paso 6.

La aprobación aprovisiona Sandbox y Live a la vez. Para cada entorno Paymos reutiliza tu única
clave Payment activa —o crea una si no existe— y apunta un webhook Invoice hacia esta tienda: un
webhook existente se reutiliza solo si coinciden exactamente URL, categoría y proyecto, mientras
que otro webhook distinto en la misma dirección se deja intacto sin sobrescribirlo. La autorización
de dispositivo es solo un canal de entrega; su token caduca rápido, se descarta y las llamadas a
la Merchant API van firmadas con HMAC.

## Almacenamiento de secretos

Las credenciales y el estado temporal del dispositivo se guardan como un ajuste de OpenCart
cifrado con AES-256-GCM y clave derivada de `config_encryption`. Los secretos guardados no se
vuelven a mostrar en la página de administración y no existe ningún campo que los acepte; al
guardar el formulario el sobre sellado permanece intacto. Vuelve a conectar tras rotar una clave
o un secreto de webhook.

## Ajustes

| Ajuste | Qué controla |
|---|---|
| Status | Si el método se ofrece en el checkout |
| Mode | Sandbox o Live |
| Credentials | Solo lectura — indica si esta tienda está conectada |
| Webhook URL | Solo lectura — registrada automáticamente, visible para probarla |
| Title | Nombre del método que lee el comprador en el checkout |
| Button text | Texto del botón que abre la página de pago alojada |
| Pending / Confirming / Paid / Failed / Cancelled status | Tu estado de pedido para cada fase del pago |
| Sort order | Posición entre los demás métodos de pago |

Webhook en el que queda registrado OpenCart:

```text
https://your-store.example/index.php?route=extension/paymos/payment/paymos.callback
```

## Estados de pedido

OpenCart crea el pedido como siempre, lo coloca en tu **Pending status** con una línea de
historial *Awaiting Paymos payment* y envía al comprador a la página de pago alojada. A partir de
ahí el callback dirige el resto.

| Estado del pago | Estado usado | Predeterminado en una instalación limpia |
|---|---|---|
| Transferencia vista, acumulando confirmaciones | Confirming status | Processing |
| Llegó parte del importe o una transferencia confirmada desapareció por un reorg | Pending status | Pending |
| Factura pagada o pagada en exceso | Paid status | Complete |
| Pago insuficiente pasado el plazo | Failed status | Failed |
| Expirada o cancelada | Cancelled status | Canceled |

Cada transición escribe una línea en el historial del pedido indicando la factura de Paymos, y la
línea de pago incluye el hash de transacción y su enlace al explorador cuando el pago se liquida
en cadena.

Dos comportamientos prevalecen sobre la tabla. Una vez que un pedido está en tu Paid status, un
callback tardío o fuera de orden no puede hacerlo retroceder: el estado obsoleto queda anotado en
el historial y se ignora. Además, un callback pagado cuyo importe ya no coincide con el pedido
pasa a Confirming status con la nota *needs manual review* en lugar de completarse, porque un
total de pedido modificado tras emitirse la factura no debe liquidarlo automáticamente el software.

## Pruebas en Sandbox

1. Deja **Mode** en Sandbox, habilita el método y realiza un pedido desde tu propia tienda.
2. Busca esa factura en el panel de Paymos y dispara el resultado deseado: pagada, pagada en exceso, pago insuficiente o cancelada. Sandbox no confirma nada por temporizador.
3. Revisa la pestaña de historial del pedido: cada evento debe aparecer en orden con el estado que mapeaste.
4. Cambia **Mode** a Live. Ambos entornos salieron de la misma conexión, así que no hay nada más que configurar.

Los pagos en Sandbox no tienen una cadena detrás, por lo que no aparece hash de transacción ni
enlace al explorador en la línea pagada. Es completamente normal.

## Callbacks y conciliación

Cada entrega lleva `X-Webhook-Signature` con formato `t={timestamp},v1={hmac_hex}`, HMAC-SHA256
sobre los bytes exactos enviados, comparado en tiempo constante y aceptado con cualquiera de los
dos secretos durante una rotación. Los IDs de evento se guardan para confirmar reenvíos sin
reaplicarlos al pedido.

Nunca se confía en un callback terminal solo por su firma: la factura se vuelve a obtener desde la
Merchant API y se compara con la instantánea guardada antes de mover el pedido.

Aquí la conciliación de facturas es un botón, no un cron. **Reconcile Paymos orders**, en la parte
superior de la página de ajustes, toma hasta 50 facturas sin resolver de las últimas 24 horas,
consulta cada una en la API, aplica el mismo mapeo y las mismas protecciones, concilia reembolsos
pendientes e indica cuántos registros actualizó. Úsalo tras una caída o cuando un pedido parezca
atascado.

## Reembolsos

La extensión admite reembolsos nativos mediante la Merchant API de Paymos (`/v1/refunds`) en dos
modos calculados por el servidor: `full` (reembolso íntegro de la factura) y `overpayment`
(reembolso del exceso en una factura `paid_over`). Los fondos se devuelven como una nueva
transacción saliente en blockchain estrictamente a la dirección `first_sender` verificada que
Paymos registró en la factura pagada: no se introduce manualmente ninguna dirección de billetera
destinataria ni se admiten importes parciales arbitrarios.

Para enviar o consultar un reembolso en OpenCart:

1. Abre **Extensions → Extensions → Payments → Paymos** en el panel de administración de OpenCart.
2. Introduce el ID del pedido de OpenCart en el panel **Paymos refunds** (`refundPanel`) para cargar los importes reembolsables, la dirección `first_sender` verificada y las operaciones existentes.
3. Elige la acción de reembolso `full` o `overpayment` (`extension/paymos/payment/paymos.refund`).

Al instalar o abrir la extensión se registra una tarea cron de OpenCart de 1 minuto
(`paymos_refunds` → `extension/paymos/payment/paymos.refundCron`), y tanto los callbacks firmados
como el botón **Reconcile Paymos orders** también hacen avanzar los reembolsos pendientes. Los
reembolsos completados quedan registrados en el historial del pedido de OpenCart tras verificarse
frente a la Merchant API, y el modo Sandbox permite simular resultados finales (`completed`,
`failed`, `cancelled`).

## Resolución de problemas

**Paymos no permanece instalado.** La tienda ejecuta una versión de OpenCart anterior a 4.0.2.0.
La extensión se elimina sola, no crea ninguna tabla y deja una línea `[Paymos] Installation
refused` con la versión detectada en el registro de errores de OpenCart. Actualiza OpenCart y
vuelve a instalar Paymos.

**Connect indica que la tienda debe ser accesible por HTTPS.** Un OpenCart recién instalado puede
tener la URL de tienda vacía, en cuyo caso se utiliza la URL del catálogo; si esta sigue en http,
la conexión se rechaza en lugar de quedar a medias. El mensaje muestra la URL encontrada: coloca
su versión https en tu `config.php` de OpenCart e inténtalo de nuevo.

**El pedido se queda en Pending para siempre.** Solicita la URL de callback anterior desde fuera de
tu red. Debe responder sin autenticación HTTP y sin redirecciones, ya que no se siguen
redirecciones. Después revisa las líneas `[Paymos]` en el registro de errores de OpenCart.

**El checkout lanza "Paymos is temporarily unavailable".** No se pudo crear la factura. La línea del
registro indica el motivo; las causas habituales son un entorno que nunca terminó de conectarse o
una moneda de pedido en la que el proyecto no factura.

**Un pedido necesita revisión manual.** Su total cambió entre la creación de la factura y la
llegada del pago. La nota del historial incluye ambas cifras; resuélvelo manualmente.

- [Documentación](https://paymos.io/es/docs/cms-opencart)
- [Código fuente](https://github.com/paymos-labs/opencart)
- [Historial de cambios](CHANGELOG.md)
- [Soporte](mailto:support@paymos.io)
