# Madar_StockAvailability Module

## Overview

The `Madar_StockAvailability` module manages product deliverability based on customer location and inventory sources. It integrates with Magento's inventory management system, precomputes deliverability data, and provides a seamless frontend experience using the Hyvä theme.

## Features

- **Deliverability Calculation:** Determines if products are deliverable to a customer's location based on proximity and stock availability.
- **Precomputed Data:** Utilizes cron jobs to precompute deliverability statuses, enhancing performance.
- **Frontend Integration:** Provides intuitive UI components for location selection and deliverability feedback.
- **Admin Configuration:** Allows administrators to manage delivery ranges, hubs, and associated inventory sources.

## Installation

1. **Clone the Module:**

   ```bash
   git clone https://your-repo-url.git app/code/Madar/StockAvailability
