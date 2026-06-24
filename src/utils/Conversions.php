<?php

namespace VindiPaymentGateways;

class VindiConversions
{

  /**
   * Converts the months, weeks and years of a Trial period into days.
   *
   * Used to send days in parameter  to Vindi.
   *
   *
   * @since 1.0.1
   *
   * @return number
   */
  public static function convertTriggerToDay($number, $type = 'month')
  {
    $length = (int) $number;
    if ($length <= 0) {
      return 0;
    }

    $daysMap = [
      'day'   => 1,
      'week'  => 7,
      'month' => 30,
      'year'  => 365,
    ];

    return $length * ($daysMap[$type] ?? 1);
  }
  /**
   * Converts the months, weeks and years of a Trial period into days.
   *
   * Used to send days in parameter  to Vindi.
   *
   *
   * @since 1.0.1
   *
   * @return number
   */
  public static function convert_interval($interval_count, $interval_type = 'month')
  {
    $count = max(1, (int) $interval_count);

    $map = [
      'day'   => 'days',
      'week'  => 'days',
      'month' => 'months',
      'year'  => 'months',
    ];

    $multiplier = [
      'day'   => 1,
      'week'  => 7,
      'month' => 1,
      'year'  => 12,
    ];

    return [
      'interval'       => $map[$interval_type] ?? 'months',
      'interval_count' => $count * ($multiplier[$interval_type] ?? 1),
    ];
  }
}
