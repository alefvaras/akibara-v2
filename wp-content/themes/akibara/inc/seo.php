<?php
/**
 * Akibara SEO enhancements.
 * - Meta descriptions for non-Rank Math pages
 * - rel="next/prev" for paginated archives
 * - JSON-LD BreadcrumbList for products & categories
 * - JSON-LD Product schema for WooCommerce products
 * - Open Graph meta for product pages
 * - Canonical URLs
 * - Noindex for junk URLs (feeds, query params, etc.)
 * - Twitter Card meta
 *
 * @package Akibara
 * @version 3.0.0
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/seo/noindex.php';
require_once __DIR__ . '/seo/meta.php';
require_once __DIR__ . '/seo/canonical.php';
require_once __DIR__ . '/seo/schema-product.php';
require_once __DIR__ . '/seo/schema-collection.php';
require_once __DIR__ . '/seo/schema-organization.php';
require_once __DIR__ . '/seo/schema-faq.php';
require_once __DIR__ . '/seo/schema-article.php';
require_once __DIR__ . '/seo/category-intro.php';
require_once __DIR__ . '/seo/rank-math-filters.php';

