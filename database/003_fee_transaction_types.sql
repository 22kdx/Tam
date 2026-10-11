ALTER TABLE treasury_transactions MODIFY transaction_type ENUM(
  'opening','deposit','withdrawal','transfer_in','transfer_out',
  'exchange_in','exchange_out','fee_in','fee_out','reversal'
) NOT NULL;
