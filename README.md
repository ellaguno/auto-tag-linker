# Auto Tag Linker

Un plugin de WordPress que automáticamente crea enlaces en el contenido basado en etiquetas de WordPress y palabras personalizadas.

## Características

- Vincula automáticamente palabras que coincidan con etiquetas de WordPress
- Permite definir una lista personalizada de palabras y sus URLs
- Opción de búsqueda interna automática para palabras sin URL específica
- Lista negra para excluir palabras específicas
- Control de estilo CSS personalizado
- Soporte para múltiples tipos de post
- Control por post individual
- Apertura de enlaces en nueva ventana (opcional)

## Instalación

1. Descarga el plugin
2. Sube la carpeta `auto-tag-linker` al directorio `/wp-content/plugins/`
3. Activa el plugin a través del menú 'Plugins' en WordPress

## Configuración

### Configuración General

1. Ve a `Ajustes > Auto Tag Linker`
2. Configura las opciones básicas:
   - Número máximo de enlaces por palabra/etiqueta
   - Abrir enlaces en nueva ventana
   - Tipos de post habilitados

### Fuentes de Enlaces

Puedes habilitar dos tipos de fuentes para los enlaces:

1. **Etiquetas de WordPress**
   - Usa las etiquetas existentes en tu sitio
   - Los enlaces apuntan a los archivos de etiquetas

2. **Lista Personalizada**
   - Define tus propias palabras y URLs
   - Formato: `palabra|URL`
   - Si no se especifica URL, se usa la búsqueda interna

### Ejemplo de Lista Personalizada
```
WordPress|https://wordpress.org
PHP|https://php.net
JavaScript
```

### Lista Negra

Añade palabras que no quieres que se conviertan en enlaces:
```
palabra1
palabra2
palabra3
```

### Personalización CSS

El CSS por defecto hace que los enlaces se vean como texto normal:
```css
.auto-tag-link {
    text-decoration: none !important;
    color: inherit;
}
.auto-tag-link:hover {
    text-decoration: none !important;
    color: inherit;
}
```

## Control por Post

En cada post individual encontrarás una opción para desactivar el auto-linking específicamente para ese contenido.

## Uso en Desarrollo

Para extender o modificar el plugin:

1. Clona el repositorio
```bash
git clone https://github.com/tuusuario/auto-tag-linker.git
```

2. Estructura del plugin:
```
auto-tag-linker/
├── auto-tag-linker.php
└── README.md
```

## Requisitos

- WordPress 5.0 o superior
- PHP 7.0 o superior

## Contribuir

1. Haz un Fork del proyecto
2. Crea una rama para tu función (`git checkout -b feature/AmazingFeature`)
3. Haz commit de tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## Licencia

Distribuido bajo la Licencia GPL v2 o posterior. Ver `LICENSE` para más información.

## Contacto
Eduardo Llaguno - [@ellaguno](https://twitter.com/ellaguno)

Link del Proyecto: [https://github.com/tuusuario/auto-tag-linker](https://github.com/tuusuario/auto-tag-linker)
