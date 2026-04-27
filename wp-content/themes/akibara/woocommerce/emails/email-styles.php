<?php
/**
 * Email Styles — Akibara Brand Identity
 * Override of WooCommerce email-styles.php
 *
 * Tokens usados (v3 Manga Crimson, 2026-04-22):
 * bg-dark #0A0A0A · bg-card #161618 · accent #D90010 · link #FF4D4D
 * border #2A2A2E · text-primary #F5F5F5 · text-secondary #A0A0A0 · text-muted #666
 *
 * @version 11.0.0
 * @package Akibara
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$font = "'Helvetica Neue', Arial, 'Segoe UI', sans-serif";
?>

/* ═══════════════════════════════════════
   AKIBARA EMAIL DESIGN SYSTEM
   ═══════════════════════════════════════ */

/* Base */
body {
    background-color: #0A0A0A;
    margin: 0;
    padding: 0;
    text-align: center;
    -webkit-text-size-adjust: 100%;
    -ms-text-size-adjust: 100%;
}

#outer_wrapper {
    background-color: #0A0A0A;
}

#inner_wrapper {
    background-color: #161618;
    border: 1px solid #2A2A2E;
    border-radius: 4px;
}

/* Header */
#template_header_image img {
    display: block;
    width: 160px;
    height: auto;
    margin: 0 auto;
}

#header_wrapper {
    padding: 0 32px 8px;
}

#header_wrapper h1 {
    text-align: center;
}

h1 {
    color: #FFFFFF;
    font-family: <?php echo $font; ?>;
    font-size: 24px;
    font-weight: 700;
    line-height: 1.3;
    margin: 0;
    letter-spacing: -0.3px;
}

h2 {
    color: #FFFFFF;
    font-family: <?php echo $font; ?>;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.4;
    margin: 0 0 16px;
    text-align: left;
}

h3 {
    color: #FFFFFF;
    font-family: <?php echo $font; ?>;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.4;
    margin: 16px 0 8px;
    text-align: left;
}

h2.email-order-detail-heading span {
    color: #8A8A8A;
    display: block;
    font-size: 13px;
    font-weight: normal;
}

/* Links */
a {
    color: #FF4D4D;
    text-decoration: none;
    font-weight: normal;
}

a:hover {
    text-decoration: underline;
}

/* Body content */
#body_content {
    background-color: #161618;
}

#body_content_inner {
    color: #B0B0B0;
    font-family: <?php echo $font; ?>;
    font-size: 15px;
    line-height: 1.6;
    text-align: left;
}

#body_content p {
    margin: 0 0 16px;
}

/* Email introduction block */
.email-introduction {
    padding-bottom: 20px;
}

.email-introduction p {
    color: #B0B0B0;
}

/* Divider */
.hr {
    border-bottom: 1px solid #2A2A2E;
    margin: 16px 0;
}
.hr-top { margin-top: 24px; }
.hr-bottom { margin-bottom: 24px; }

/* Images */
img {
    border: none;
    display: inline-block;
    font-size: 14px;
    height: auto;
    outline: none;
    text-decoration: none;
    vertical-align: middle;
    max-width: 100%;
}

/* ═══ ORDER DETAILS TABLE ═══ */

.td {
    color: #B0B0B0;
    border: 0;
    vertical-align: middle;
}

/* Order items */
#body_content table .email-order-details td,
#body_content table .email-order-details th {
    padding: 10px 12px;
    color: #B0B0B0;
    font-family: <?php echo $font; ?>;
    font-size: 14px;
    border-bottom: 1px solid #1A1A1A;
}

#body_content table .email-order-details th {
    color: #8A8A8A;
    font-weight: normal;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
}

#body_content table .email-order-details td:first-child,
#body_content table .email-order-details th:first-child {
    padding-left: 0;
}

#body_content table .email-order-details td:last-child,
#body_content table .email-order-details th:last-child {
    padding-right: 0;
}

#body_content .email-order-details tbody tr:last-child td {
    border-bottom: 1px solid #2A2A2E;
    padding-bottom: 20px;
}

#body_content .email-order-details tfoot tr:first-child td,
#body_content .email-order-details tfoot tr:first-child th {
    padding-top: 20px;
}

/* Order item image — P0 fix: WC hardcodes 32-48px; forzar 80px (portrait manga). */
#body_content .email-order-details img {
    width: 80px !important;
    height: auto !important;
    min-width: 80px;
    max-width: 80px;
    display: block;
    border-radius: 4px;
    margin-right: 12px;
    object-fit: cover;
}

/* Item meta (variation, size, etc) */
.email-order-item-meta {
    color: #8A8A8A;
    font-size: 12px;
    line-height: 1.4;
}

#body_content td ul.wc-item-meta {
    font-size: 12px;
    margin: 6px 0 0;
    padding: 0;
    list-style: none;
}

#body_content td ul.wc-item-meta li {
    margin: 3px 0 0;
    padding: 0;
    color: #8A8A8A;
}

#body_content td ul.wc-item-meta li p {
    margin: 0;
}

#body_content .email-order-details .wc-item-meta-label {
    float: left;
    font-weight: normal;
    margin-right: 4px;
    color: #525252;
}

/* Order totals */
#body_content .email-order-details .order-totals td,
#body_content .email-order-details .order-totals th {
    font-weight: normal;
    padding: 4px 0;
    color: #B0B0B0;
    border-bottom: 0;
}

#body_content .email-order-details .order-totals-total th {
    color: #FFFFFF;
    font-weight: 700;
}

#body_content .email-order-details .order-totals-total td {
    color: #FFFFFF;
    font-weight: 700;
    font-size: 20px;
}

#body_content .email-order-details .order-totals .includes_tax {
    display: block;
    font-size: 12px;
    color: #8A8A8A;
}

#body_content .email-order-details .order-totals-last td,
#body_content .email-order-details .order-totals-last th {
    border-bottom: 1px solid #2A2A2E;
    padding-bottom: 20px;
}

#body_content .email-order-details .order-customer-note td {
    border-bottom: 1px solid #2A2A2E;
    padding: 20px 0;
    color: #B0B0B0;
    font-style: italic;
}

#body_content .order-item-data td {
    border: 0 !important;
    padding: 0 !important;
    vertical-align: middle;
}

/* ═══ ADDRESSES ═══ */

.address {
    color: #B0B0B0;
    font-style: normal;
    padding: 12px 0;
    word-break: break-all;
    font-family: <?php echo $font; ?>;
    font-size: 14px;
    line-height: 1.6;
}

.address-title {
    color: #FFFFFF;
    font-family: <?php echo $font; ?>;
    font-weight: 700;
}

#addresses td + td {
    padding-left: 16px !important;
}

/* Additional fields */
.additional-fields {
    padding: 12px;
    color: #B0B0B0;
    border: 1px solid #2A2A2E;
    border-radius: 4px;
    list-style: none;
}

.additional-fields li {
    margin: 0 0 10px;
}

/* ═══ ADDITIONAL CONTENT ═══ */

#body_content table td td.email-additional-content {
    color: #8A8A8A;
    font-family: <?php echo $font; ?>;
    padding: 24px 0 0;
    font-size: 13px;
}

.email-additional-content p {
    text-align: center;
}

.email-additional-content-aligned p {
    text-align: left;
}

/* ═══ FOOTER ═══ */

#template_footer td {
    padding: 0;
}

#template_footer #credit {
    border: 0;
    color: #525252;
    font-family: <?php echo $font; ?>;
    font-size: 11px;
    line-height: 1.5;
    text-align: center;
    padding: 20px 32px;
}

#template_footer #credit p {
    margin: 0;
    color: #525252;
}

#template_footer #credit a {
    color: #8A8A8A;
    text-decoration: none;
}

/* ═══ BANK DETAILS (BACS) ═══ */

.wc-bacs-bank-details-heading {
    color: #FFFFFF;
    font-size: 18px;
}

.wc-bacs-bank-details-account-name {
    color: #FFD700;
    font-size: 15px;
    font-weight: 700;
}

.wc-bacs-bank-details {
    background-color: #1A1A1A;
    border: 1px solid #2A2A2E;
    border-radius: 4px;
    padding: 16px 20px;
    margin: 16px 0;
}

.wc-bacs-bank-details ul {
    list-style: none;
    padding: 0;
    margin: 8px 0 0;
}

.wc-bacs-bank-details li {
    color: #B0B0B0;
    font-family: <?php echo $font; ?>;
    font-size: 14px;
    padding: 4px 0;
    border-bottom: 1px solid #222222;
}

.wc-bacs-bank-details li:last-child {
    border-bottom: 0;
}

.wc-bacs-bank-details li strong {
    color: #FFFFFF;
}

/* ═══ UTILITY ═══ */

.text { color: #B0B0B0; font-family: <?php echo $font; ?>; }
.link { color: #FF4D4D; }
.font-family { font-family: <?php echo $font; ?>; }
.text-align-left { text-align: left; }
.text-align-right { text-align: right; }
.order-item-data { color: #FFFFFF; font-family: <?php echo $font; ?>; }

.email-logo-text {
    color: #D90010;
    font-family: <?php echo $font; ?>;
    font-size: 22px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
}

/* WC container compatibility */
#wrapper { margin: 0 auto; padding: 0; max-width: 600px; width: 100%; }
#template_container { background-color: #161618; border: 0; }
#template_header { background-color: #161618; border: 0; color: #FFFFFF; }
#template_header h1, #template_header h1 a { color: #FFFFFF; }

/* ═══ RESPONSIVE ═══ */

@media only screen and (max-width: 600px) {
    #body_content_inner {
        font-size: 15px !important;
        line-height: 1.6 !important;
    }
    h1 {
        font-size: 20px !important;
    }
    h2, .email-order-detail-heading {
        font-size: 16px !important;
    }
    #body_content table > tbody > tr > td,
    #template_header_image,
    #header_wrapper {
        padding-left: 16px !important;
        padding-right: 16px !important;
    }
    .email-order-details .order-totals-total td {
        font-size: 16px !important;
    }
    .email-additional-content {
        padding-top: 16px !important;
    }
}
