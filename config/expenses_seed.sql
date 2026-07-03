-- Sample monthly expenses for Car Wash Konak
-- Owner: update amounts / add rows each month as needed.
-- Run once after deploying the expenses table from schema.sql.

INSERT INTO expenses (date, category, amount, note) VALUES
('2026-06-01', 'Rent',        8000.00, 'Stockton location monthly rent'),
('2026-06-01', 'Utilities',   2500.00, 'Electric + water + gas'),
('2026-06-01', 'Chemicals',   3200.00, 'Wash chemicals monthly supply'),
('2026-06-01', 'Supplies',     800.00, 'Towels, vacuums, misc supplies'),
('2026-06-01', 'Maintenance', 1200.00, 'Equipment maintenance'),
('2026-06-01', 'Marketing',    500.00, 'Social media + flyers'),
('2026-06-01', 'Insurance',   1500.00, 'Business insurance premium'),

('2026-07-01', 'Rent',        8000.00, 'Stockton location monthly rent'),
('2026-07-01', 'Utilities',   2800.00, 'Higher summer electricity'),
('2026-07-01', 'Chemicals',   3200.00, 'Wash chemicals monthly supply'),
('2026-07-01', 'Supplies',     750.00, 'Towels, vacuums, misc supplies'),
('2026-07-01', 'Maintenance',  400.00, 'Minor repairs'),
('2026-07-01', 'Marketing',    500.00, 'Social media + flyers'),
('2026-07-01', 'Insurance',   1500.00, 'Business insurance premium');
