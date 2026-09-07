# Theme SDK & Storefront UI Documentation

This directory documents the **real, implemented** Theme SDK of the Hyper Commerce Platform — every field, class, and behavior described here exists in source (`app/Core/Theme/`, `themes/default/`), not aspirational design.

- **[developer-guide.md](developer-guide.md)** — the complete Theme Developer Handbook: directory structure, manifest reference, activation, inheritance, layouts/sections/components, ProductType templates, translations/RTL, safe overrides, security boundaries, and a worked example.

## Theme Architecture Principles

1. **Default Theme (`themes/default`)**: Simple, functional, professional, responsive; built with standard Tailwind CSS 4 and daisyUI 5 defaults. It is also the **fallback** every other theme's inheritance chain resolves to.
2. **Component Abstractions**: All views must use the existing `<x-ui.*>` component library (`button`, `modal`, `table`, `input`, `select`, `tabs`, `card`, `alert`, `badge`, `breadcrumbs`, `checkbox`, `confirm-dialog`, `drawer`, `dropdown`, `empty-state`, `pagination`, `radio`, `stats`, `textarea`, `toast`) — never raw framework markup duplicated per theme.
3. **No Direct Framework Bleed**: Domain views must not be polluted with raw CSS framework details.
4. **Strict RTL/LTR Support**: Use logical CSS utility classes (`ms`, `me`, `ps`, `pe`, `start`, `end`) throughout all storefront and control center components.
5. **Child Themes & Overrides**: Real, implemented — see [developer-guide.md § Inheritance](developer-guide.md#inheritance--child-themes).

For the platform's current compatibility baseline (Laravel/PHP/Tailwind/daisyUI versions a theme is built against), see [../DEVELOPER-CENTER-VERSION.md](../DEVELOPER-CENTER-VERSION.md).
