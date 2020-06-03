<?php

namespace App\Http\Controllers;

use App\UserDocument;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Its\Sso\OpenIDConnectClient;
use Its\Sso\OpenIDConnectClientException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserDocumentController extends Controller
{
    public function getSsoAccessToken()
    {
        try {
            $client = new Client();
            $response = $client->post(env('TOKEN_ENDPOINT'), [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => env('ITS_CLIENT_ID'),
                    'client_secret' => env('ITS_CLIENT_SECRET')
                ]
            ]);
            $decodedResponse = json_decode($response->getBody());
            return $decodedResponse->token_type . ' '. $decodedResponse->access_token;
        } catch (Exception $e) {
            throw new Exception("Cant Get Access Token");
        }
    }

    public function introspectAccessToken($token)
    {
        try {
            $client = new Client();
            $response = $client->post(env('INTROSPECT_ENDPOINT'), [
                'headers' => [
                    'Authorization' => $this->getSsoAccessToken()
                ],
                'form_params' => [
                    'token' => $token
                ]
            ]);
            $decodedResponse = json_decode($response->getBody());
            return $decodedResponse;
        } catch (\Throwable $th) {
            return null;
        }
    }

    public function getAllUserDocuments(Request $request)
    {
        try {
            $authorization = $request->header('Authorization');
            if ($authorization == null) return response()->json([ 'message' => 'Unauthorized' ], 401);
            $token = str_replace("Bearer ", "", $authorization);
            $introspect = $this->introspectAccessToken($token);
            if ($introspect->active == false) return response()->json([ 'message' => 'Unauthorized' ], 401);
            $userDocuments = UserDocument::with('document')->where('user_id', $introspect->sub)->get();
            $documentResponse = [];
            foreach($userDocuments as $userDocument) {
                array_push($documentResponse,[
                    'id' => $userDocument->id,
                    'title' => $userDocument->document->name,
                    'is_signed' => $userDocument->signed == 1 ? true : false
                ]);
            }
            return response()->json([
                "data" => $documentResponse,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([ 'message' => $th->getMessage() ], 500);
        }
    }

    public function saveUserDocument(Request $request)
    {
        $this->validate($request, [
            'document_id' => 'required'
        ]);
        $authorization = $request->header('Authorization');
        if ($authorization == null) return response()->json([ 'message' => 'Unauthorized' ], 401);
        $token = str_replace("Bearer ", "", $authorization);
        $introspect = $this->introspectAccessToken($token);
        if ($introspect->active == false) return response()->json([ 'message' => 'Unauthorized' ], 401);
        UserDocument::create([
            'user_id' => $introspect->sub,
            'document_id' => $request->document_id
        ]);
        return response()->json([ 'message' => 'success' ]);
    }

    public function saveUserSignedDocument(Request $request)
    {
        $this->validate($request, [
            'document' => 'required',
            'user_document_id' => 'required'
        ]);
        $authorization = $request->header('Authorization');
        if ($authorization == null) return response()->json([ 'message' => 'Unauthorized' ], 401);
        $token = str_replace("Bearer ", "", $authorization);
        $introspect = $this->introspectAccessToken($token);
        if ($introspect->active == false) return response()->json([ 'message' => 'Unauthorized' ], 401);
        $userDocumentId = $request->user_document_id;
        $document = $request->file('document');
        $userDocument = UserDocument::find($userDocumentId);
        if ($userDocument == null) return response()->json(['message' => 'User Dokumen tidak Ditemukan'], 404);
        if ($userDocument->user_id !== $introspect->sub) return response()->json([ 'message' => 'Unauthorized' ], 401);
        $client = new Client();
        $response = $client->post(env('FILE_STORAGE_URL') . '/documents', [
            'multipart' => [
                [
                    'name'     => 'document',
                    'contents' => file_get_contents($document->getRealPath()),
                    'filename' => $document->getClientOriginalName() 
                ]
            ]
        ]);
        $documentInformation = json_decode($response->getBody());
        $userDocument->document_id = $documentInformation->id;
        $userDocument->signed = true;
        $userDocument->save();
        return response()->json(['message' => 'success']);
    }

    public function getDocumentByDocumentId(Request $request)
    {
        $documentId = $request->document_id;
        $userDocument = UserDocument::find($documentId);
        if ($userDocument == null) return response()->json(['message' => 'Not Found' ], 404);
        $client = new Client();
        $response = $client->request(
            'GET', env('FILE_STORAGE_URL') . '/documents/' . $userDocument->document_id, ['stream' => true]
        );
        $contentDisposition = $response->getHeader('Content-Disposition');
        $body = $response->getBody();

        $response = new StreamedResponse(function() use ($body) {
            while (!$body->eof()) {
                echo $body->read(1024);
            }
        });

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $contentDisposition);

        return $response;
    }
}
