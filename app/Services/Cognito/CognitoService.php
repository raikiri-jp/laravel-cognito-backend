<?php

namespace App\Services\Cognito;

use App\Models\LoginHistory;
use App\Models\Token;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;

/**
 * Amazon Cognito Service.
 *
 * @author Miyahara Yuuki <59301668+raikiri-jp@users.noreply.github.com>
 */
class CognitoService {
  // TODO scopeについては保留 (優先度:低)
  // /**
  //  * デフォルトスコープの取得.
  //  *
  //  * @return array ログイン時にデフォルトで指定するスコープ
  //  */
  // protected static function getDefaultScopes() {
  //   return explode(',', env('COGNITO_SCOPES', 'openid,profile'));
  // }

  /**
   * ログイン画面の表示.
   *
   * Amazon Cognito によりホストされたログイン画面を表示する。
   *
   * @param string $redirectUri ログイン後のリダイレクト先URI
   * @param array $scopes スコープ (スコープはUserInfo エンドポイントで取得できる内容に影響する)
   * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
   * @see https://docs.aws.amazon.com/ja_jp/cognito/latest/developerguide/cognito-user-pools-app-integration.html
   */
  public static function toLogin(string $redirectUri, array $scopes = []) {
    // セッションを破棄
    session()->flush();
    // CognitoのUIを利用してログイン
    $domain = env('COGNITO_OAUTH2_DOMAIN');
    $clientId = env('COGNITO_APP_CLIENT_ID');
    $uri = "https://$domain/login?" . http_build_query([
      'client_id' => $clientId,
      'response_type' => 'code',
      'redirect_uri' => $redirectUri,
    ], '', '&', PHP_QUERY_RFC1738);
    return redirect($uri);
  }

  /**
   * ログアウト後に任意の画面にリダイレクト.
   *
   * @param string $redirectUri リダイレクト先URI
   * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
   */
  public static function logout(string $redirectUri) {
    // セッションを破棄
    session()->flush();
    // Cognito側でもログアウトし、指定のURIにリダイレクトする
    $domain = env('COGNITO_OAUTH2_DOMAIN');
    $clientId = env('COGNITO_APP_CLIENT_ID');
    $uri = "https://$domain/logout?" . http_build_query([
      'client_id' => $clientId,
      'logout_uri' => $redirectUri,
    ], '', '&', PHP_QUERY_RFC1738);
    return redirect($uri);
  }

  /**
   * ログアウト後にログイン画面を表示.
   *
   * @param string $redirectUri ログイン後のリダイレクト先URI
   * @param array $scopes スコープ (スコープはUserInfo エンドポイントで取得できる内容に影響する)
   * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
   */
  public static function logoutAndLogin(string $redirectUri, array $scopes = []) {
    // セッションを破棄
    session()->flush();
    // Cognito側でもログアウトして、ログイン画面を表示する
    $domain = env('COGNITO_OAUTH2_DOMAIN');
    $clientId = env('COGNITO_APP_CLIENT_ID');
    $uri = "https://$domain/logout?" . http_build_query([
      'client_id' => $clientId,
      'response_type' => 'code',
      'redirect_uri' => $redirectUri,
    ], '', '&', PHP_QUERY_RFC1738);
    return redirect($uri);
  }

  /**
   * 認可処理.
   *
   * 認可コードは1度使用すると使えなくなるため、同じURIにアクセスするとエラーとなる。
   * 当メソッド利用後に別URIにリダイレクトすることでエラーを回避できる。
   *
   * @param string $authorizationCode 認可コード
   * @param string $redirectUri ログイン時に指定したリダイレクトURI (ログイン後の遷移先)
   * @throws AuthenticationException An error occurred during authorization
   * @return User ユーザ情報
   * @see https://docs.aws.amazon.com/ja_jp/cognito/latest/developerguide/token-endpoint.html
   */
  public static function authorize(string $authorizationCode, string $redirectUri): User {
    // 認可コードとトークンを交換
    $tokens = static::requestToken($authorizationCode, $redirectUri);
    $accessToken = $tokens['access_token'];
    $refreshToken = $tokens['refresh_token'];
    $expiresIn = $tokens['expires_in'];

    // UserInfo エンドポイントよりユーザ属性を取得
    $userInfo = static::requestUserInfo($accessToken);
    $sub = $userInfo['sub'];
    $email = $userInfo['email'];
    $name = $userInfo['name'];

    // ユーザ情報をユーザテーブルに登録
    $user = User::updateOrCreate([
      'email' => $email
    ], [
      'name' => $name,
      'sub' => $sub,
    ]);

    // アクセストークンとリフレッシュトークンをトークンテーブルに登録
    $token = new Token();
    $token->user_id = $user->id;
    $token->access_token = $accessToken;
    $token->refresh_token = $refreshToken;
    $token->expires_at = Carbon::now()->addSeconds($expiresIn);
    $token->save();

    // ログイン履歴をログイン履歴テーブルに登録
    // TODO ログイン履歴が肥大化しすぎないよう、程よいところで削除する仕組みを作ること
    $loginHistory = new LoginHistory();
    $loginHistory->user_id = $user->id;
    $loginHistory->ip_address = Request::ip();
    $loginHistory->login_at = Carbon::now();
    $loginHistory->save();

    return $user;
  }

  /**
   * トークンの問合せ.
   *
   * 認可コードは1度使用すると使えなくなるため、同じURIにアクセスするとエラーとなる。
   * 当メソッド利用後に別URIにリダイレクトすることでエラーを回避できる。
   *
   * @param string $authorizationCode 認可コード
   * @param string $redirectUri ログイン時に指定したリダイレクトURI (ログイン後の遷移先)
   * @return array `id_token`、`access_token`、`refresh_token`、`expires_in` を含む配列
   * @throws AuthenticationException An error occurred during authorization
   */
  private static function requestToken(string $authorizationCode, string $redirectUri): array {
    // トークンエンドポイント
    $domain = env('COGNITO_OAUTH2_DOMAIN');
    $clientId = env('COGNITO_APP_CLIENT_ID');
    $secret = env('COGNITO_APP_SECRET');
    $uri = "https://$domain/oauth2/token";
    $headers = [
      'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $secret)
    ];
    $data = [
      'grant_type' => 'authorization_code',
      'code' => $authorizationCode,
      'redirect_uri' => $redirectUri,
    ];
    $cognitoResponse = Http::asForm()->withHeaders($headers)->post($uri, $data);
    $response = $cognitoResponse->json();
    if ($cognitoResponse->failed()) {
      $errorMessage = $response['error_description'] ?? 'An error occurred during authorization';
      throw new AuthenticationException($errorMessage);
    }

    return [
      'id_token' => $response['id_token'],
      'access_token' => $response['access_token'],
      'refresh_token' => $response['refresh_token'],
      'expires_in' => $response['expires_in'],
    ];
  }

  /**
   * ユーザ属性の問合せ.
   *
   * @param string $accessToken Access Token
   * @return array ユーザ属性
   */
  private static function requestUserInfo(string $accessToken) {
    $domain = env('COGNITO_OAUTH2_DOMAIN');
    $uri = "https://$domain/oauth2/userInfo";
    $userInfo = Http::withToken($accessToken)->get($uri);
    $data = [
      'sub' => $userInfo['sub'],
      'email' => $userInfo['email'],
      'username' => $userInfo['username'],
      'name' => @$userInfo['name'],
      'family_name' => @$userInfo['family_name'],
      'given_name' => @$userInfo['given_name'],
      'department' => @$userInfo['custom:department'],
      'position' => @$userInfo['custom:position'],
    ];
    return $data;
  }

  /**
   * アクセストークンの更新.
   *
   * @return void
   */
  public static function refreshAccessToken(): void {
    // FIXME リフレッシュトークンを使用してアクセストークンを更新する
  }
}
