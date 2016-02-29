<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\EmailToken;
use App\Models\User;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\Registrar;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{

    protected $auth;

    protected $registrar;


    public function __construct(Guard $auth,Registrar $registrar){
        $this->auth = $auth;
        $this->registrar = $registrar;
    }

    public function login(Request $request){
        /*登录表单处理*/
        if($request->isMethod('post'))
        {

            $request->flashOnly('email');
            /*表单数据校验*/
            $this->validate($request, [
                'email' => 'required|email',
                'password' => 'required|min:6',
                'captcha' => 'required|captcha'
            ]);

            /*只接收email和password的值*/
            $credentials = $request->only('email', 'password');

            /*根据邮箱地址和密码进行认证*/
            if ($this->auth->attempt($credentials, $request->has('remember')))
            {


                if($this->credit($request->user()->id,'login',Setting()->get('coins_login'),Setting()->get('credits_login'))){
                    $message = '登陆成功! 经验 '.integer_string(Setting()->get('credits_login')) .' , 金币 '.integer_string(Setting()->get('coins_login'));
                   return $this->success(route('website.index'),$message);
                }

                /*认证成功后跳转到首页*/
                return redirect()->to(route('website.index'));

            }

            /*登录失败后跳转到首页，并提示错误信息*/
            return redirect(route('auth.user.login'))
                ->withInput($request->only('email', 'remember'))
                ->withErrors([
                    'password' => '用户名或密码错误，请核实！',
                ]);

        }

        return view("theme::account.login");
    }

    /**
     * 用户注册入口
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     */
    public function register(Request $request)
    {

        /*注册表单处理*/
        if($request->isMethod('post'))
        {
            $request->flashExcept(['password','password_confirmation']);
            $validator = $this->registrar->validator($request->all());
            if ($validator->fails())
            {
                $this->throwValidationException(
                    $request, $validator
                );
            }
            $formData = $request->all();
            $formData['visit_ip'] = $request->getClientIp();

            $this->auth->login($this->registrar->create($formData));
            $message = '注册成功!';
            if($this->credit($request->user()->id,'register',Setting()->get('coins_register'),Setting()->get('credits_register'))){
                $message .= ' 经验 '.integer_string(Setting()->get('credits_register')) .' , 金币 '.integer_string(Setting()->get('coins_register'));
            }

            /*发送邮箱验证邮件*/
            EmailToken::createAndSend([
                'email' => $formData['email'],
                'name' => $formData['name'],
                'action' => 'register',
                'subject' => '欢迎注册'.Setting()->get('website_name').',请激活您注册的邮箱！',
                'token' => EmailToken::createToken(),
            ]);


            return $this->success(route('website.index'),$message);
        }
        return view("theme::account.register");
    }


    /*忘记密码*/
    public function forgetPassword(Request $request)
    {

        if($request->isMethod('post'))
        {
            $request->flashOnly('email');
            /*表单数据校验*/
            $this->validate($request, [
                'email' => 'required|email|exists:users',
                'captcha' => 'required|captcha'
            ]);

            $emailToken = EmailToken::createAndSend([
                'email' => $request->input('email'),
                'action' => 'findPassword',
                'name'=>Setting()->get('website_name').'用户',
                'subject' => Setting()->get('website_name').'找回密码',
                'token' => EmailToken::createToken(),
            ]);

            return view("theme::account.forgetPassword")->with('success','ok')->with('email',$request->input('email'));

        }


        return view("theme::account.forgetPassword");

    }


    public function findPassword($token,Request $request)
    {
        if($request->isMethod('post')){

            $this->validate($request, [
                'password' => 'required|min:6',
                'captcha' => 'required|captcha'
            ]);

            $emailToken = EmailToken::where('action','=','findPassword')->where('token','=',$token)->first();
            if(!$emailToken){
                return $this->error(route('website.ask'),'token信息不存在，请重新找回');
            }

            if($emailToken->created_at->diffInMinutes() > 60){

                return $this->error(route('website.ask'),'token信息已失效，请重新找回');
            }

            $user = User::where('email','=',$emailToken->email)->first();

            if(!$user){
                return $this->error(route('website.ask'),'用户不存在或已被删除');
            }

            $user->password = Hash::make($request->input('password'));
            $user->save();

            $user->attachRole(2); //默认注册为普通用户角色

            EmailToken::clear($user->email,'findPassword');

            return $this->success(route('auth.user.login'),'密码修改成功,请重新登录');

        }

        return view("theme::account.findPassword")->with('token',$token);

    }



    /**
     * 用户登出
     */
    public function logout(){

        $this->auth->logout();

        return redirect()->to(route('website.index'));

    }



}