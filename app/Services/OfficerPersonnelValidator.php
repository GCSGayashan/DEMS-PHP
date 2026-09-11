<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;

final class OfficerPersonnelValidator
{
    private const SERVICE_PERMANENCY_VALUES=[
        'PERMANENT_IN_SERVICE',
        'NOT_PERMANENT_IN_SERVICE',
    ];

    /** @return array{arpa_service_permanency:string,service_permanented_date:?string} */
    public static function servicePermanency(mixed $value,mixed $permanentedDate):array
    {
        $value=trim((string)$value);
        if($value==='')throw new DomainException('Service Permanency is required.');
        if(!in_array($value,self::SERVICE_PERMANENCY_VALUES,true))throw new DomainException('Service Permanency is invalid.');

        $permanentedDate=trim((string)$permanentedDate) ?: null;
        if($permanentedDate!==null&&!self::isValidDate($permanentedDate))throw new DomainException('Permanented Date is invalid.');
        if($value==='PERMANENT_IN_SERVICE'&&$permanentedDate===null)throw new DomainException('Permanented Date is required for an Officer who is Permanent In Service.');

        // This column represents the current permanency state, not historical evidence.
        if($value!=='PERMANENT_IN_SERVICE')$permanentedDate=null;

        return [
            'arpa_service_permanency'=>$value,
            'service_permanented_date'=>$permanentedDate,
        ];
    }

    /** @return array{primary_mobile:?string,alternative_mobile:?string} */
    public static function contactNumbers(mixed $telephone,mixed $whatsApp):array
    {
        $telephoneRaw=trim((string)$telephone);
        $whatsAppRaw=trim((string)$whatsApp);
        if($telephoneRaw===''&&$whatsAppRaw==='')throw new DomainException('Please provide at least one contact number.');

        $telephoneNumber=self::normalizeSriLankanMobile($telephoneRaw);
        $whatsAppNumber=self::normalizeSriLankanMobile($whatsAppRaw);
        if($telephoneRaw!==''&&$telephoneNumber===null)throw new DomainException('Telephone Number must be entered as 0XXXXXXXXX or +94XXXXXXXXX.');
        if($whatsAppRaw!==''&&$whatsAppNumber===null)throw new DomainException('WhatsApp Number must be entered as 0XXXXXXXXX or +94XXXXXXXXX.');

        return [
            'primary_mobile'=>$telephoneNumber,
            'alternative_mobile'=>$whatsAppNumber,
        ];
    }

    public static function normalizeSriLankanMobile(mixed $value):?string
    {
        $value=trim((string)$value);
        if($value==='')return null;
        $value=preg_replace('/[\s().-]+/','',$value) ?? $value;
        if(preg_match('/^0(\d{9})$/',$value,$matches)===1)return '+94'.$matches[1];
        if(preg_match('/^94\d{9}$/',$value)===1)return '+'.$value;
        if(preg_match('/^0094(\d{9})$/',$value,$matches)===1)return '+94'.$matches[1];
        return preg_match('/^\+94\d{9}$/',$value)===1 ? $value : null;
    }

    private static function isValidDate(string $value):bool
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))return false;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date!==false&&$date->format('Y-m-d')===$value;
    }
}
