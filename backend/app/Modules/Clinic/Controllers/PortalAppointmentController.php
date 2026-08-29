<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Auth\CurrentUser;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Clinic\Services\PortalAppointmentService;

final class PortalAppointmentController extends ApiController
{
    private PortalAppointmentService $service;
    public function __construct(){ $this->service=new PortalAppointmentService(\Config\Services::appointmentService(),\Config\Services::queueService(),\Config\Services::notificationOutbox()); }
    public function index():ResponseInterface
    { $this->authorize('portal.appointments.read'); return $this->ok(['appointments'=>$this->service->appointments(CurrentUser::assert(),$this->query('department'),$this->query('status'),$this->query('from'),$this->query('to'))]); }
    public function slots():ResponseInterface
    { $this->authorize('portal.appointments.read'); $d=(string)($this->request->getGet('department')??'');$from=(string)($this->request->getGet('from')??'');$to=(string)($this->request->getGet('to')??'');if(!in_array($d,['clinic','counselling'],true)||$from===''||$to==='')throw ApiException::validationFailure([['code'=>'validation.field','message'=>'department, from, and to are required.']]);$p=$this->request->getGet('provider_user_id');return $this->ok(['slots'=>$this->service->slots($d,$from,$to,$p!==null?(int)$p:null)]); }
    public function create():ResponseInterface
    { $this->authorize('portal.appointments.manage');$p=$this->request->getJSON(true)??[];$rules=['department'=>'required|in_list[clinic,counselling]','provider_user_id'=>'required|is_natural_no_zero','starts_at'=>'required|max_length[40]','reason'=>'permit_empty|max_length[255]','type'=>'permit_empty|in_list[initial,follow_up,crisis,referral_based]'];if(!$this->makeValidation($rules)->run($p))throw ApiException::validationFailure($this->errors());return $this->ok($this->service->book(CurrentUser::assert(),$p),null,201); }
    public function cancel(string $department,int $id):ResponseInterface
    { $this->authorize('portal.appointments.manage');if(!in_array($department,['clinic','counselling'],true))throw ApiException::validationFailure([['code'=>'validation.field','message'=>'Unknown department.','field'=>'department']]);return $this->ok($this->service->cancel(CurrentUser::assert(),$department,$id)); }
    public function queues():ResponseInterface
    { $this->authorize('portal.queue.read');return $this->ok(['queues'=>$this->service->queues(CurrentUser::assert())]); }
    private function query(string $key):?string{$v=(string)($this->request->getGet($key)??'');return$v!==''?$v:null;}
    private function errors():array{$out=[];foreach($this->validation->getErrors()as$f=>$m)$out[]=['code'=>'validation.field','message'=>(string)$m,'field'=>(string)$f];return$out;}
}
