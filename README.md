# Dox Feedback

Feedback de cliente y revisión visual para WordPress: comentarios anclados, aprobaciones y sign-off, y enlaces de revisión **sin login** — una página, varias, o el sitio entero, con reviewers invitados por email y roles. Nativo de **Bricks**, **Elementor** y **Gutenberg**; funciona en cualquier tema.

Todo en un único plugin — sin add-ons ni claves de licencia.

## Características

- **Comentarios anclados** en cualquier elemento (escritorio, tablet o móvil)
- **Respuestas en hilo** y reacciones emoji de un toque
- **Estados** — abierto, en progreso, resuelto — con filtros
- **Enlaces de revisión** — una página, una selección o el sitio entero bajo un único enlace sin login
- **Reviewers invitados por email con roles** — Viewer / Reviewer / Approver / Lead, cada uno autenticado por un magic link privado
- **Aprobaciones de cliente** — el reviewer marca una página como aprobada y obtienes un registro de sign-off con fecha
- **Funciona en modo mantenimiento / próximamente** para los reviewers invitados, manteniendo el sitio privado al público
- **Notificaciones por email** al comentar, responder o aprobar
- **UI nativa del builder** — el overlay adopta el aspecto de Bricks, Elementor o el editor de bloques
- **Pins anclados a elementos** que sobreviven a las ediciones de página

## Privacidad

Dox Feedback guarda todos los datos de comentarios **en tu propia base de datos** (`wp_dxf_*`) y **no hace ninguna llamada externa** ("phone-home") de ningún tipo.

## Requisitos

- WordPress 6.4+
- PHP 8.1+

## Instalación

1. Descarga el ZIP de la [última release](https://github.com/davidzoque/dox-feedback/releases/latest) (`dox-feedback.zip`).
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**, elige el ZIP y actívalo.
3. Abre cualquier página en Bricks, Elementor o el editor de bloques y aparece el overlay de comentarios — o usa el botón **Dox Feedback** de la barra de administración para iniciar una revisión de la página actual o del sitio entero.

## Actualizaciones automáticas

Incluye [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) apuntando a las **releases** de este repositorio (público), así que **no requiere configuración ni token**: las actualizaciones aparecen en la pantalla normal de plugins de WordPress.

## Créditos

Dox Feedback es un fork de "Reviso – Client Feedback & Approvals" (GPL-2.0-or-later). Las revisiones multi-página / de sitio completo y los reviewers invitados por email con roles son implementaciones originales de Dox Studio sobre los hooks de extensión del proyecto original. Ver [NOTICE.md](NOTICE.md).

## Licencia

GPL-2.0-or-later
