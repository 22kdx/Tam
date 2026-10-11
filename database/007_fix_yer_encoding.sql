SET NAMES utf8mb4;
UPDATE currencies
SET name='الريال اليمني', symbol='ر.ي', `precision`='0',
    thousand_separator=',', decimal_separator='.'
WHERE code='YER';
