<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use AWS;


class SmsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        session_id('checker');
        session_start();
    }

    public function send(Request $request)
    {
        $_SESSION["pay_status"] = $_SESSION["pay_status"] ?? true;
        if ($_SESSION["pay_status"] !== true) {
            return response()->json(['result' => 'ERROR', 'status' => 'No quota left for account'], 500);
        }
        $this->validate(
            $request,
            [
                'number' => 'required|numeric|digits_between:11,13', // regex:/^([0-9\s\-\+\(\)]*)$/ 380952812117
                'text' => 'required|string|max:128',
                'alpha' => 'sometimes|required|string|min:3|max:20',
            ],
            [
                // 'subject.required' => 'subject має бути заповнене.'
            ],
            [
                // 'subject' => '"Тема"',
            ]
        );

        try {
            $sms = AWS::createClient('sns');
        } catch (\Throwable $th) {
            throw $th;
        }

        $phoneNumber = '+' . $request->number; //

        // Set the Sender ID for your message
        $sender_id = $request->alpha ?? 'KyivDigital';

        // Create a message object
        $message = [
            'Message' => $request->text,
            'PhoneNumber' => $phoneNumber,
        ];

        // Set the Sender ID for the message
        $message['MessageAttributes'] = [
            'AWS.SNS.SMS.SenderID' => [
                'DataType' => 'String',
                'StringValue' => $sender_id,
            ],
        ];

        // Try to send message.
        try {
            $camel = $sms->publish($message);
        } catch (\Exception $e) {
            throw $e;
        }
        return response()->json(['result' => 'SUCCESS', 'ID' => $camel['MessageId']], 200);

        $check = $this->status($camel['MessageId']);
        if ($check[1]["value"] === "FAILURE") {
            if($check[4]["value"] === "No quota left for account"){
                $_SESSION["pay_status"] = false;
                return response()->json(['result' => 'FAILURE', 'status' => 'No quota left for account'], 500);
            }
            $_SESSION["pay_status"] = true;
            return response()->json(['result' => 'FAILURE', 'data' => $check], 400);
        }else {
            $_SESSION["pay_status"] = true;
            return response()->json(['result' => 'SUCCESS', 'ID' => $camel['MessageId']], 200);
        }
        return "Egorka...";

    }

    public function statusCheck(Request $request)
    {
        $this->validate(
            $request,
            [
                'message_id' => 'required_without:number|string|max:128',
                'start_time' => 'sometimes|required|numeric', //unix time format; By default strtotime('-3 day').
                'end_time' => 'sometimes|required|numeric|gte:start_time', //unix time format; By default time().
                'number' => 'required_without:message_id|numeric|digits_between:11,13',
                'success' => 'sometimes|required|boolean',//value 0,1
            ],
            [
                // 'subject.required' => 'subject має бути заповнене.'
            ],
            [
                // 'subject' => '"Тема"',
            ]
        );

        $check = $this->status($request->message_id,$request->start_time,$request->end_time,$request->number,$request->success??false );

        if ($check[0][1]["value"]??'' and $check[0][1]["value"] === "FAILURE") {
            if ($check[0][4]["value"] === "No quota left for account") {
                return response()->json(['result' => 'FAILURE', 'status' => 'No quota left for account', 'data'=>$check], 500);
            }
            return response()->json(['result' => 'FAILURE', 'data' => $check], 400);
        }elseif ($check[0][1]["value"]??'' and $check[0][1]["value"] === "SUCCESS") {
            return response()->json(['result' => 'SUCCESS', 'data' => $check], 200);
        }
        return response()->json(['result' => 'EMPTY', 'data' => $check], 400);
    }

    public function status($message_id, $start_time = null, $end_time = null, $number = null, bool $success = false)
    {


        try {
            $sms = AWS::createClient('CloudWatchLogs');
        } catch (\Throwable $th) {
            throw $th;
        }

        if ($success === false) {
            $logGroupName = 'sns/eu-central-1//DirectPublishToPhoneNumber/Failure';
        }elseif($success === true) {
            $logGroupName = 'sns/eu-central-1//DirectPublishToPhoneNumber';
        }
        if($number){
            $query = 'fields @timestamp as timestamp, status , notification.messageId as `Message ID`, delivery.destination as `Destination phone number`, delivery.providerResponse as `Provider response` | filter delivery.destination = "+' . $number . '" | limit 5';
        }else{
            $query = 'fields @timestamp as timestamp, status , notification.messageId as `Message ID`, delivery.destination as `Destination phone number`, delivery.providerResponse as `Provider response` | filter notification.messageId = "' . $message_id . '" | limit 1';
        }

        $startTime = $start_time ?? strtotime('-3 day');
        $endTime = $end_time ?? time();
        $startTime = (int) $startTime;
        $endTime = (int) $endTime;

        try {
            $camel = $sms->startQuery([
                'logGroupName' => $logGroupName,
                'queryString' => $query,
                'startTime' => $startTime, // Replace with the appropriate start time for the log events
                'endTime' => $endTime, // Replace with the appropriate end time for the log events
            ])['queryId'];
        } catch (\Exception $e) {
            throw $e;
        }
        do {
            sleep(1); // Wait for the query to complete
            $result = $sms->getQueryResults([
                'queryId' => $camel,
            ]);
        } while ($result['status'] == 'Running');
        if (count($result) > 1) {
            $temp = $result['results'];
        }else{
            $temp = $result['results'][0] ?? [1 => ["value"  => "SUCCESS","result"=>$result]];
        }
        return $temp;
    }

}
