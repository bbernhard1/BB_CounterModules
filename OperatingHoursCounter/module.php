<?php

declare(strict_types=1);

class BB_Betriebsstundenzaehler extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyInteger('Source', 0);
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyBoolean('CalcDaily', false);
        $this->RegisterPropertyBoolean('CalcWeekly', false);
        $this->RegisterPropertyBoolean('CalcMonthly', false);
        $this->RegisterPropertyBoolean('CalcYearly', false);

        //VariableProfiles
        if (!IPS_VariableProfileExists('BSZ.OperatingHours')) {
            IPS_CreateVariableProfile('BSZ.OperatingHours', 2);
            IPS_SetVariableProfileText('BSZ.OperatingHours', '', $this->Translate(' hours'));
            IPS_SetVariableProfileDigits ('BSZ.OperatingHours', 1);
        }

        //Variables
        $this->RegisterVariableFloat('OperatingHours', $this->Translate('Hours of Operation'), 'BSZ.OperatingHours', 50);

        //Timer
        $this->RegisterTimer('UpdateCalculationTimer', 0, 'BSZ_Calculate($_IPS[\'TARGET\']);');

        //Messages
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

       if ($this->ReadPropertyBoolean('CalcDaily')) {
            $this->RegisterVariableFloat('OperatingHoursDay', $this->Translate('Hours of Operation this day'), 'BSZ.OperatingHours', 10);
        }
       
        if ($this->ReadPropertyBoolean('CalcWeekly')) {
            $this->RegisterVariableFloat('OperatingHoursWeek', $this->Translate('Hours of Operation this week'), 'BSZ.OperatingHours', 20);
        }

        if ($this->ReadPropertyBoolean('CalcMonthly')) {
          $this->RegisterVariableFloat('OperatingHoursMonth', $this->Translate('Hours of Operation this month'), 'BSZ.OperatingHours', 30);
        }

        if ($this->ReadPropertyBoolean('CalcYearly')) {
            $this->RegisterVariableFloat('OperatingHoursYear', $this->Translate('Hours of Operation this year'), 'BSZ.OperatingHours', 40);
        }

        //Only call this in READY state. On startup the ArchiveControl instance might not be available yet
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->setupInstance();
        }
  
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        //Calculate when the archive module is loaded
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->setupInstance();
        }
    }

    public function Calculate()
    {
        $errorState = $this->getErrorState();

        if ($errorState != 102) {
            $statuscodes = [];
            $statusForm = json_decode(IPS_GetConfigurationForm($this->InstanceID), true)['status'];
            foreach ($statusForm as $status) {
                $statuscodes[$status['code']] = $status['caption'];
            }
            echo $this->Translate($statuscodes[$errorState]);
            return;
        }

              

// overall        
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $values = AC_GetAggregatedValues($archiveID, $this->ReadPropertyInteger('Source'), 4, 0, time(), 0);
        $this->SendDebug('AggregatedValues', json_encode($values), 0);
        $seconds = 0;
        foreach ($values as $value) {
            $seconds += $value['Avg'] * $value['Duration'];
        }
        $this->SetValue('OperatingHours', ($seconds / (60 * 60)));


// calc OperatingHours per day
 if ($this->ReadPropertyBoolean('CalcDaily')) {        
        $values = AC_GetAggregatedValues($archiveID, $this->ReadPropertyInteger('Source'), 1, strtotime('today 00:00:00', time()), time(), 0);
        $seconds = 0;
        foreach ($values as $value) {
            $seconds += $value['Avg'] * $value['Duration'];
        }
        $this->SetValue('OperatingHoursDay', ($seconds / (60 * 60)));
    }

// calc OperatingHours per week
 if ($this->ReadPropertyBoolean('CalcWeekly')) {        
        $values = AC_GetAggregatedValues($archiveID, $this->ReadPropertyInteger('Source'), 2, strtotime('last monday 00:00:00', time()), time(), 0);
        $seconds = 0;
        foreach ($values as $value) {
            $seconds += $value['Avg'] * $value['Duration'];
        }
        $this->SetValue('OperatingHoursWeek', ($seconds / (60 * 60)));
    }

// calc OperatingHours per month
 if ($this->ReadPropertyBoolean('CalcMonthly')) {
        $values = AC_GetAggregatedValues($archiveID, $this->ReadPropertyInteger('Source'), 3, strtotime('first day of this month 00:00:00', time()), time(), 0);
        $seconds = 0;
        foreach ($values as $value) {
            $seconds += $value['Avg'] * $value['Duration'];
        }
        $this->SetValue('OperatingHoursMonth', ($seconds / (60 * 60)));
    }

// calc OperatingHours per year
  if ($this->ReadPropertyBoolean('CalcYearly')) {
        $values = AC_GetAggregatedValues($archiveID, $this->ReadPropertyInteger('Source'), 4, strtotime('1st january 00:00:00', time()), time(), 0);
        $seconds = 0;
        foreach ($values as $value) {
            $seconds += $value['Avg'] * $value['Duration'];
        }
        $this->SetValue('OperatingHoursYear', ($seconds / (60 * 60)));
    }

    }

    private function setupInstance()
    {
        $newStatus = 102;

        if (!$this->ReadPropertyBoolean('Active')) {
            $newStatus = 104;
        } else {
            $newStatus = $this->getErrorState();
        }
        $this->SetStatus($newStatus);
        if ($newStatus != 102) {
            $this->SetTimerInterval('UpdateCalculationTimer', 0);
            $this->SetValue('OperatingHours', 0);
            return;
        }

        if ($this->GetTimerInterval('UpdateCalculationTimer') < ($this->ReadPropertyInteger('Interval') * 1000 * 60)) {
            $this->SetTimerInterval('UpdateCalculationTimer', $this->ReadPropertyInteger('Interval') * 1000 * 60);
        }
        $this->Calculate();
    }

    private function getErrorState()
    {
        $source = $this->ReadPropertyInteger('Source');
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        //102 suggests everything is working not the active status
        $returnState = 102;
        if ($source == 0) {
            $returnState = 202;
        } elseif (!IPS_VariableExists($source)) {
            $returnState = 200;
        } elseif (!AC_GetLoggingStatus($archiveID, $source) || (IPS_GetVariable($source)['VariableType'] != 0)) {
            $returnState = 201;
        }

        return $returnState;
    }
}
