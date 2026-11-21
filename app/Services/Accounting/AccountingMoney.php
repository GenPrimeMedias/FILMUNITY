<?php
namespace App\Services\Accounting;
use InvalidArgumentException;
final class AccountingMoney {
 public static function decimal(string $amount): string {
  if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D',$amount)) throw new InvalidArgumentException('Use a non-negative INR amount with at most two decimal places.');
  return bcadd($amount,'0',2);
 }
 public static function minor(string $amount): int { return (int)bcmul(self::decimal($amount),'100',0); }
 public static function fromMinor(int|string $minor): string { return bcdiv((string)$minor,'100',2); }
 public static function add(string $a,string $b): string { return bcadd($a,$b,2); }
 public static function equal(string $a,string $b): bool { return bccomp($a,$b,2)===0; }
}
