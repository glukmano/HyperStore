# Catalog Module Specification

**Module Namespace**: `Modules\Catalog`  
**Root Path**: `modules/Catalog/`  
**Status**: Active Production Module (PHASE-03)

---

## 1. Overview & Architectural Boundaries

The `Catalog` module is the first production module in the HyperStore Modular Monolith. It owns:
1. Canonical Product definitions and lifecycle.
2. 22 First-Party Product Types and extensible registration.
3. Hierarchical Categories with depth cycle prevention.
4. Brands with localized slugs.
5. Typed Attribute Storage and relational Select/Multiselect indexing.
6. Order-independent Product Variants.
7. Customizable Customer Input Fields.
8. Product Bundles and Extensible Relationships.
9. Multi-Store / Market / Channel Publications with localized store slugs.
10. Control Center Livewire Management Interfaces.

---

## 2. Directory Layout

```
modules/Catalog/
├── module.json
├── CatalogServiceProvider.php
├── Contracts/
│   ├── ProductTypeInterface.php
│   ├── ProductTypeRegistryInterface.php
│   ├── ProductTypeDefinition.php
│   ├── CategoryHierarchyValidatorInterface.php
│   └── VariantCombinatorInterface.php
├── DTOs/
│   ├── ProductData.php
│   ├── VariantData.php
│   ├── AttributeValueData.php
│   ├── StorePublicationData.php
│   └── CategoryData.php
├── Models/
│   ├── Product.php
│   ├── ProductTranslation.php
│   ├── ProductVariant.php
│   ├── ProductVariantOption.php
│   ├── ProductAttributeValue.php
│   ├── ProductAttributeOption.php
│   ├── Category.php
│   ├── CategoryTranslation.php
│   ├── CategoryStore.php
│   ├── Brand.php
│   ├── BrandTranslation.php
│   ├── Attribute.php
│   ├── AttributeTranslation.php
│   ├── AttributeOption.php
│   ├── AttributeOptionTranslation.php
│   ├── AttributeSet.php
│   ├── AttributeGroup.php
│   ├── ProductCustomField.php
│   ├── ProductCustomFieldTranslation.php
│   ├── ProductCustomFieldOption.php
│   ├── ProductCustomFieldOptionTranslation.php
│   ├── ProductBundleItem.php
│   ├── ProductRelationship.php
│   ├── ProductStoreListing.php
│   └── ProductStoreListingTranslation.php
├── ProductTypes/
│   ├── ProductTypeRegistry.php
│   └── (22 First-Party Type Definitions)
├── Services/
│   ├── CategoryHierarchyService.php
│   ├── VariantCombinatorService.php
│   └── CatalogMediaService.php
├── Actions/
│   ├── CreateProductAction.php
│   ├── UpdateProductAction.php
│   ├── ArchiveProductAction.php
│   ├── PublishProductToStoreAction.php
│   ├── CreateVariantAction.php
│   ├── AssignAttributeValuesAction.php
│   └── CreateCategoryAction.php
├── Events/
│   ├── ProductCreated.php
│   ├── ProductUpdated.php
│   ├── ProductArchived.php
│   ├── ProductPublishedToStore.php
│   ├── VariantCreated.php
│   └── CategoryCreated.php
├── Http/Controllers/Api/V1/
│   ├── ProductApiController.php
│   ├── CategoryApiController.php
│   ├── BrandApiController.php
│   ├── AttributeApiController.php
│   ├── AttributeSetApiController.php
│   └── ProductTypeApiController.php
├── Livewire/
│   ├── ProductList.php
│   ├── ProductForm.php
│   ├── CategoryManager.php
│   ├── AttributeManager.php
│   ├── AttributeSetManager.php
│   └── BrandManager.php
├── Resources/
│   └── views/livewire/
└── Routes/
    ├── api.php
    └── web.php
```
