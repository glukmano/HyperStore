# Theme SDK & Storefront UI Documentation

This directory contains specifications and guidelines for the Theme System and UI component layer of the **Hyper Commerce Platform**.

## Theme Architecture Principles

1. **Default Theme (`themes/default`)**: Simple, functional, professional, responsive; built with standard Tailwind CSS 4 and daisyUI 5 defaults.
2. **Component Abstractions**: All views must use clean, reusable component abstractions (e.g. `<x-ui.button>`, `<x-ui.modal>`, `<x-ui.table>`, `<x-ui.input>`, `<x-ui.select>`, `<x-ui.tabs>`, `<x-ui.card>`).
3. **No Direct Framework Bleed**: Domain views must not be polluted with raw CSS framework details.
4. **Strict RTL/LTR Support**: Use logical CSS utility classes (`ms`, `me`, `ps`, `pe`, `start`, `end`) throughout all storefront and control center components.
5. **Child Themes & Overrides**: Support clean template inheritance, asset bundling, and Page Builder section registration.
