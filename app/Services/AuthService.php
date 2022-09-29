<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Util\CustomResponse;
use App\Http\Resources\UserResource;
use App\Http\Requests\{LoginRequest, VerifyAccount, 
    ResetPassword, ChangePassword, CreateUser, PasswordReset as PassReset};
use App\Mail\{VerifyAccountMail, ResetPasswordMail};
use Illuminate\Support\Facades\{DB, Mail, Hash, Http};
use App\Actions\Fortify\{CreateNewUser, ResetUserPassword};
use App\Models\{User, Wallet, Role, UserProfile, ChefProfile, PasswordReset};


class AuthService
{
    public function login(LoginRequest $request)
    {
        try{
            $user = User::where("email", $request->email)->first();
            if(!$user || !password_verify($request->password, $user->password)):
                $message = "Wrong credentials";
                return CustomResponse::error($message, 400);
            elseif(!$user->email_verified_at):
                $message = "Email address not verified, please verify your email before you can login";
                return CustomResponse::error($message, 401);
            endif;
            
            $token = $user->createToken("MarketStreet")->plainTextToken;
            $user->token = $token;
            $message = 'Login successfully';
            return CustomResponse::success($message, $user);
        }catch(\Exception $e){
            $message = $e->getMessage();
            return CustomResponse::error($message);
        }
    }

    public function register(CreateUser $request)
    {
        try{
            $createUser = new CreateNewUser;
            $user = $createUser->create($request->input());

            $token = $user->createToken("MarketStreet")->plainTextToken;
            $user->token = $token;
        }catch(\Exception $e){
            $message = $e->getMessage();
            return CustomResponse::error($message);
        }

        $message = 'Thanks for signing up! Please check your email to complete your registration.';
        return CustomResponse::success($message, $user, 201);
    }

    public function logout()
    {
        auth()->user()->tokens->each(function ($token, $key) {
            $token->delete();
        });

        return CustomResponse::success("User has been logged out", null);
    }

    public function refresh()
    {
        $user = auth()->user();

        $user->tokens->each(function ($token, $key) {
            $token->delete();
        });

        $token = $user->createToken("MarketStreet")->plainTextToken;
        return CustomResponse::success("token refreshed successfully", $token);
    }

    public function verifyEmail(VerifyAccount $request)
    {
        $check = DB::table('user_verification')
        ->where([
            'email' => $request['email'], 
            'code' => $request['code']
        ])->first();
        
        if(!is_null($check)):
            $user = User::where(['email' => $check->email])->first();
            $user->email_verified_at = Carbon::now();
            $user->save();

            DB::table('user_verification')
            ->where(['email' => $request['email']])
            ->delete();

            $message = 'Your email address is verified successfully.';
            return view('auth.email-verification-success', ['user' => $user ]);
        endif;
    }

    public function resetPassword(ResetPassword $request)
    {
        $user = User::where(['email' => $request['email']])->first();
        $token = mt_rand(1000, 9999);
        $expiry_time = Carbon::now()->addMinutes(6);

        try{
            PasswordReset::updateOrCreate([
                'email' => $user->email
            ],[
                'token' => $token,
                'expiry_time' => $expiry_time
            ]);
            $message = 'A password reset email has been sent! Please check your email.';    

            Mail::to($user->email)
                ->send(new ResetPasswordMail($user, $token));
        }catch(\Exception $e){
            $error_message = $e->getMessage();
            return CustomResponse::error($error_message);
        }

        return CustomResponse::success($message, null);
    }

    public function verifyResetToken(Request $request)
    {
        $validator = Validator::make($request, [
            'email' => 'required|email',
            'token' => 'required|numeric|exists:password_resets'
        ]);

        $tokenedUser = DB::table('password_resets')
        ->where([
            'token' => $request['token'], 
            'email' => $request['email']
        ])->first();

        if(!is_null($tokenedUser)):
            if($tokenedUser->expiry_time > Carbon::now()):
                return view('auth.password-reset', [
                    'email' => $request['email'],
                    'token' => $request['token']
                ]);
            endif;
        endif;
    }

    public function passwordReset(PassReset $request)
    {   
        try{
            $user = User::where(['email' => $request->email])->first();
            $resetUser = new ResetUserPassword;
            $reset = $resetUser->reset($user, $request->input());

            $message = 'Your password has been changed!';
        }catch(\Exception $e){
            $error_message = $e->getMessage();
            return CustomResponse::error($error_message);
        }

        return CustomResponse::success($message, null);
    }

    public function changePassword(ChangePassword $request)
    {   
        $user = auth()->user();
        try{
            if((Hash::check($request['current_password'], $user->password)) == false):
                $message = "Check your old password.";
            elseif((Hash::check($request['password'], $user->password)) == true):
                $message = "Please enter a password which is not similar to your current password.";
            else:
                $user->password = $request['password'];
                $user->save();

                $message = "Your password has been changed successfully";
                return CustomResponse::success($message, null);
            endif;
        }catch(\Exception $e){
            $error_message = $e->getMessage();
            return CustomResponse::error($error_message);
        }
        
        return CustomResponse::error($message, 400);
    }

    public function saveFCMToken(Request $request)
    {
        $user = auth()->user();
        try{
            $user->fcm_token = $request['token'];
            $user->save();

            $message = 'FCM token updated successfully';
        }catch(\Exception $e){
            $error_message = $e->getMessage();
            return CustomResponse::error($error_message);
        }
        return CustomResponse::success($message, null);
    }

}