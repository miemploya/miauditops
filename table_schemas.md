### Table: station_lube_grn

| Field | Type | Null | Default |
|-------|------|------|---------|
| id | int(11) | NO |  |
| company_id | int(11) | NO |  |
| session_id | int(11) | YES |  |
| supplier_id | int(11) | YES |  |
| grn_number | varchar(50) | YES |  |
| grn_date | date | YES |  |
| invoice_number | varchar(100) | YES |  |
| total_cost | decimal(15,2) | YES | 0.00 |
| notes | text | YES |  |
| created_at | timestamp | NO | current_timestamp() |

### Table: station_lube_store_items

| Field | Type | Null | Default |
|-------|------|------|---------|
| id | int(11) | NO |  |
| session_id | int(11) | NO |  |
| company_id | int(11) | NO |  |
| item_name | varchar(100) | YES |  |
| opening | decimal(12,2) | YES | 0.00 |
| received | decimal(12,2) | YES | 0.00 |
| return_out | decimal(12,2) | YES | 0.00 |
| adjustment | decimal(12,2) | YES | 0.00 |
| selling_price | decimal(12,2) | YES | 0.00 |
| sort_order | int(11) | YES | 0 |

### Table: station_lube_grn_items

| Field | Type | Null | Default |
|-------|------|------|---------|
| id | int(11) | NO |  |
| grn_id | int(11) | NO |  |
| company_id | int(11) | NO |  |
| product_id | int(11) | YES |  |
| product_name | varchar(150) | YES |  |
| quantity | decimal(12,2) | YES | 0.00 |
| unit | varchar(50) | YES | Litre |
| cost_price | decimal(12,2) | YES | 0.00 |
| selling_price | decimal(12,2) | YES | 0.00 |
| line_total | decimal(15,2) | YES | 0.00 |

