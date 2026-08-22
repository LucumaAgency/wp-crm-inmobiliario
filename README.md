# Lucuma CRM — Conector para WordPress

Renderiza en el sitio los formularios definidos en el [Lucuma CRM](https://github.com/LucumaAgency/crm-inmobiliario)
y envía los leads. Con cola de reintentos y correo de respaldo: **si el CRM no responde, no se
pierde ningún lead**.

> Estado: **Fase 1.** Shortcode operativo. El elemento nativo de Bricks y el bloque de
> Gutenberg vienen después y reutilizan el mismo render.

## Qué hace, y qué no

**Hace:** dibujar el formulario con el HTML y el CSS del sitio, enviarlo, encolarlo si falla,
reintentarlo, avisar por correo siempre, y mostrar en el admin lo que quedó sin sincronizar.

**No hace:** gestionar leads, mostrar listados comerciales ni dar acceso a asesores. Todo eso
vive en la plataforma. El equipo comercial no necesita cuenta de WordPress.

## Por qué no hay mapeo de campos

En el conector anterior (Sperant) había que declarar a mano que `form-field-vbroeu` era el
teléfono, y eso se rompía cada vez que alguien tocaba el formulario. Aquí **el formulario se
define en el CRM**, y cada campo declara qué *es* (`semantic`). El plugin solo dibuja lo que le
dicen. No hay nada que mapear ni que se pueda desincronizar.

## Instalación

1. Subir el plugin y activarlo.
2. **Ajustes → Lucuma CRM**: URL del CRM, public key y secret key (las da la plataforma al crear
   el sitio), y los correos de respaldo.
3. **Probar conexión**: lista los formularios disponibles con su shortcode listo para copiar.
4. Pegar el shortcode en la página:

```
[lucuma_crm_form id="clx..."]
```

Para no cargar el CSS mínimo del plugin y estilar todo desde el sitio:
`[lucuma_crm_form id="clx..." estilo="no"]`

## Las dos llaves

| Llave | Dónde vive | Qué permite |
|---|---|---|
| **Public key** | en el HTML de la página | pedir el esquema del formulario y enviar leads, **solo desde los dominios autorizados** en el CRM |
| **Secret key** | opción de WordPress, server side | listar formularios (prueba de conexión, selector de Bricks) |

Que la public key sea visible es intencional: lo que protege es la lista blanca de dominios.
La secret key nunca sale al navegador y se rota desde el CRM sin reinstalar nada.

## Nunca se pierde un lead

En cada envío, en este orden:

1. **Se envía al CRM** con timeout corto y una `Idempotency-Key` única.
2. **Si falla, se encola** en `{prefix}_lcrm_queue` y se reintenta con backoff exponencial
   (1 min, 5, 15, 1 h, 6 h, 24 h; seis intentos). La misma clave viaja en cada reintento, así
   que el CRM nunca duplica el lead.
3. **Se envía el correo de respaldo, siempre**, haya llegado el lead al CRM o no.
4. **Se avisa en el admin** mientras haya algo sin sincronizar, con pantalla propia para
   reintentar o descargar en CSV.

Al visitante se le muestra el mensaje de éxito aunque el envío haya quedado en cola: para él el
envío se completó, porque en efecto está guardado y garantizado.

Un `400` es la excepción: son datos inválidos y reintentar no lo arregla, así que se le pide al
visitante que corrija.

### El formulario tampoco desaparece

El esquema se cachea en un transient y además se guarda una **copia persistente**. Si el CRM no
responde al vencer la caché, el formulario se dibuja con la última versión buena. Un formulario
que desaparece porque la API tuvo un mal minuto es peor que uno ligeramente desactualizado.

## Reintentos: Action Scheduler

Se usa **Action Scheduler** si está disponible. No viene con WordPress: se instala con Composer
al empaquetar el plugin.

```bash
composer install --no-dev
```

Sin él, el plugin cae a `wp_cron`, que depende de que el sitio reciba visitas. Funciona, pero es
justo el tipo de fragilidad que este plugin existe para eliminar. Los ajustes indican cuál de los
dos está activo.

## Privacidad (Ley 29733)

- Checkbox de consentimiento **sin premarcar** y separado del botón de envío.
- Se envía al CRM qué **versión** del texto aceptó el titular, junto con la fecha y la IP.
- El modo debug **nunca** escribe datos personales en los logs.
- Al desinstalar, la cola no se borra salvo que se marque explícitamente: puede contener leads
  que todavía no llegaron al CRM.

## Antispam

Honeypot, time trap (envíos demasiado rápidos), límite por IP en el endpoint del sitio y soporte
para Cloudflare Turnstile. El CRM marca el lead como spam en vez de descartarlo, para poder
revisar falsos positivos.

## Estructura

```
crm-inmobiliario.php          arranque, activación, ajustes por defecto
includes/
  class-settings.php          pantalla de ajustes y prueba de conexión
  class-api-client.php        HTTP contra la API del CRM
  class-form-cache.php        transient + copia persistente de respaldo
  class-renderer.php          render nativo del formulario
  class-submit-endpoint.php   proxy REST: enviar, encolar, respaldar
  class-queue.php             tabla de la cola y backoff
  class-scheduler.php         Action Scheduler con fallback a wp_cron
  class-backup-mail.php       correo de respaldo
  class-admin-queue.php       pantalla de la cola y aviso en el admin
  class-shortcode.php         [lucuma_crm_form]
assets/js/form.js             idempotencia, atribución de primera visita, envío
assets/css/form.css           CSS mínimo y desactivable
```

## Hooks

```php
// Tras cada envío, haya llegado al CRM o no.
do_action( 'lucuma_crm_after_submit', $form_id, $valores, $sincronizado );
```

En el navegador, el formulario dispara `lucuma-crm:submitted` al completarse, útil para
píxeles y eventos de conversión del propio sitio.

## Pendiente

Elemento nativo de Bricks, bloque de Gutenberg, modo transición para formularios de Bricks ya
existentes, y widget de disponibilidad de unidades.
