# ADR-0022: Catalog Media Abstraction and Storage

## Status
Accepted

## Context
Catalog entities require image galleries, thumbnails, category banners, brand logos, and downloadable asset references. Domain models must not be directly coupled to third-party media package internals.

## Decision
1. Integrate `spatie/laravel-medialibrary` as the underlying media engine, configured with S3-compatible / local storage disks.
2. Abstract all media operations behind Catalog traits and explicit collection names:
   - `product_gallery`: Multiple high-resolution product imagery
   - `product_thumbnail`: Primary display card image
   - `variant_gallery`: Specific variant color/dimension images
   - `category_image`: Category banner and navigation icons
   - `brand_logo`: Brand logo vector/raster assets
3. Secure digital delivery assets (e.g. software executables, license files) remain strictly separate from public catalog media and are out of scope for Phase 03.

## Consequences
- Standardized image conversions, responsive thumbnails, and optimized web formats.
- Clean domain abstraction shielding Catalog logic from package implementation details.
