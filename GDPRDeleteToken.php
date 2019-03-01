<?php
class GDPRDeleteToken extends PluginBase {
    protected $storage = 'DbStorage';    
    static protected $description = 'Allows you to add a link to invite/reminders so a user can view token data and delete it.';
    static protected $name = 'GDPR DeleteToken';
    
    // protected $hash;
    

    
    public function init() 
    {    
        /**
         * Here you should handle subscribing to the events your plugin will handle
         */
        $this->subscribe('beforeTokenSave', 'editToken');
        $this->subscribe('newDirectRequest');
        $this->subscribe('beforeTokenEmail');
    }

    protected $settings = array(
        'bEnabled' => array(
            'type' => 'select',
            'options'=>array(
            0=>'No',
            1=>'Yes'
            ),
            'default'=>0,
            'label' => 'Enable plugin',
            'help'=>'Enable the plugin for all surveys',
        ),
        'bRedactOnOptpout' => array(
            'type' => 'select',
            'options'=>array(
            0=>'No',
            1=>'Yes'
            ),
            'default'=>0,
            'label' => 'Redact the token data when someone opts out',
            'help'=>'',
        ),
        'bShowTokenDataToUser' => array(
            'type' => 'select',
            'options'=>array(
            0=>'No',
            1=>'Yes'
            ),
            'default'=>0,
            'label' => 'Show the tokendata to the user before deleting',
            'help'=>'Users will see their name, email and token prior to confirming the deletion.',
        ),
        'sSecret' => array(
            'type' => 'string',
            'default;' => 'add-your-own-key-here',
            'label' => 'A secret to hash the url',
            'help'=>'Add a hash to the url to prevent people from randomly entering token and survey combinations. Set this only once, when you change it, all prior created links will no longer work!',
        ),
        'sConfirmHeader' => array(
            'type' => 'string',
            'label' => 'Header on the deletion confirmation page',
            'help'=>'Eg: GDPR Data deletion',
        ),
        'sConfirmText' => array(
            'type' => 'string',
            'label' => 'Text on the deletion confirmation page',
            'help'=>'',
        ),
        'sConfirmButton' => array(
            'type' => 'string',
            'label' => 'Link text',
            'help'=>'Eg Remove my data',
        ),
        'sConfirmedHeader' => array(
            'type' => 'string',
            'label' => 'Header for confirmation page',
            'help'=>'Eg Data removed',
            ),
        'sConfirmedText' => array(
            'type' => 'string',
            'label' => 'Text for confirmation page',
            'help'=>'Eg Your data has been succesfully removed. For any questions contact the following e-mail address:',
            ),
        'sEmail' => array(
            'type' => 'string',
            'label' => 'GDPR e-mail',
            'help'=>'E-mail address for people to contact regarding GDPR',
            )
    );
    
    /*
     * If the plugin is enabled, on every e-mail send the message will be checked 
     * if @@@TOKENREMOVE@@@ or @@TOKENREMOVE@@ is found it will be replaced with a clickable link/url
     */
    public function beforeTokenEmail()
    {   
        if($this->get('bEnabled'))
        {
        $body = $this->event->get("body");
        $survey = $this->event->get("survey");
        $token = $this->event->get('token');
        $secret = $this->get('sSecret');
        $hash = $this->createHash($token['firstname'], $token['lastname'], $token['email'], $token['token'],$survey, $secret );
        $removeUrl = $this->createUrl('confirm', $survey, $token['token'], $hash); 
        $newBody = str_replace("@@@TOKENREMOVE@@@", $removeUrl, $body);
        $newBody = str_replace("@@TOKENREMOVE@@", '<a href="' . $removeUrl . '">Remove</a>', $newBody);
        $this->event->set("body", $newBody);
        }
    }

    /*
     * Redact data from a token on Optout
     */
    public function editToken() 
    {
        if($this->get('bEnabled'))
        {
            $uriCorrect = (strpos(Yii::app()->request->requestUri, 'optout') !== false) ? true : false;                
            if($this->get('bRedactOnOptpout')&&$uriCorrect)
            {
                $model      = $this->event->get('model');
                if($model->emailstatus == 'OptOut')
                {
                    $this->redact($model, 'OptOutRedacted');
                }
            }
        }
    }

    /*
     * Handle the direct requests
     */
    public function newDirectRequest()
    {   
        $oEvent = $this->getEvent();
        if ($oEvent->get('target') != 'clearToken')
        {
            return;
        }
    
        if($this->get('bEnabled'))
        {
            try {
                
            $oEvent = $this->getEvent();
            $action = $oEvent->get('function');
            //get the token and surveyId from the url    
            $surveyId = $_GET['survey'];
            $token = $_GET['token'];
            $urlHash = $_GET['hash'];
            //retrieve token with that data
            $tokenObject = $this->api->getToken($surveyId, $token);
            $secret = $this->get('sSecret');
            //create hash from the data
            $hash = $this->createHash( $tokenObject->firstname, $tokenObject->lastname, $tokenObject->email, $token, $surveyId, $secret);
            
            } catch(Exception $error)
            {
                // when something goes wrong the error page is shown
            }
            //compare hash with url hash
            if($hash === $urlHash)
            {
                if($action == 'confirm')
                {
                    $this->confirm($surveyId, $token,$urlHash, $tokenObject);
                }
                if($action == 'remove')
                {
                    $this->remove($tokenObject);
                }
            }
            else
            {
                $email = $this->get('sEmail');
                echo $this->createErrorHTML($email);
            } 
        } 
    }

    /*
     * Show overciew and confirm page
     */
    protected function confirm($survey, $token, $hash, $tokenObject)
    { 
        $header = $this->get('sConfirmHeader');
        $text = $this->get('sConfirmText');
        $linkText = $this->get('sConfirmButton');
        $removeUrl = $this->createUrl('remove', $survey, $token, $hash);
        if(!$this->get('bShowTokenDataToUser'))
        {
            $tokenObject = null;
        }  
        $html = $this->createConfirmHTML($header, $text, $linkText, $removeUrl, $tokenObject);
        echo $html;
    }

    /*
     * Remove token data and show confirmation
     */
    protected function remove($tokenObject)
    {
        $this->redact($tokenObject, 'redacted');
        $email = $this->get('sEmail');
        $header = $this->get('sConfirmedHeader');
        $text = $this->get('sConfirmedText');
        $html = $this->createConfirmationHTML($header, $text, $email);
        echo $html;
    }
    
    /*
     * Create the hash to be used in the url / and to check
     */
    protected function createHash($firstname, $lastname, $email, $token, $limeId, $secret)
    {
        $data = $firstname . $lastname . $email . $token . $limeId;
        $key = $secret;
        return hash_hmac('sha256', $data, $key);
    }

    /*
     * Create the actual url
     */
    protected function createUrl($type, $survey,$token,$hash)
    {
        return App()->createAbsoluteUrl('plugins/direct',array('plugin' => 'clearToken', 'function'=>$type,'survey'=>$survey, 'token'=> $token, 'hash' => $hash));
    }

    /*
     * Redact the actual token
     */
    protected function redact($token, $emailStatus)
    {
        $token['firstname'] = "redacted";
        $token['lastname'] ="redacted";
        $token['email']="redacted@redacted.com";
        $token['usesleft']=0;
        for ($attr = 1;$attr < 25; $attr++) {
            if(isset($token['attribute_' . $attr]))
            {
            $token['attribute_' . $attr] = 'redacted';
            }
        }
        $token['emailstatus'] = $emailStatus;
        $token->save();
    }

    /*
     * Create the HTML for the confirm/overview page
     */
    protected function createConfirmHTML($header, $text, $linkText, $url, $token = null)
    {
        if($token)
        {
            $tokenHtml = '<br><br><h4>Your Data</h4><table class="table aligh-left">
            <thead>
              <tr>
                <th scope="col">First name</th>
                <th scope="col">Last name</th>
                <th scope="col">Email</th>
                <th scope="col">Token</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <th scope="row">' . $token['firstname']  .'</th>
                <td>' . $token['lastname']  .'</td>
                <td>' . $token['email']  .'</td>
                <td>' . $token['token']  .'</td>
              </tr>
            
            </tbody>
          </table>';
        } else {
            $tokenHtml = '';
        }

        $html = "<!doctype html>
        <html lang='en'>
          <head>
            <!-- Required meta tags -->
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
        
            <!-- Bootstrap CSS -->
            <link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css' integrity='sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm' crossorigin='anonymous'>
        
            <title>GDPR removal request</title>
            <style>
            body {
                padding-top: 5rem;
              }
              .gdpr {
                padding: 3rem 1.5rem;
                text-align: center;
              }

            </style>
          </head>
          <body>
          <main role='main' class='container'>

          <div class='gdpr'>
            <h1>$header</h1>
            <p class='lead'>$text</p>
            $tokenHtml
            <a href='$url' class='btn btn-danger' role='button'>$linkText</a>
            
          </div>
    
        </main><!-- /.container -->

           </body>
        </html>";
        return $html;

    }

    /*
     * Create the HTML for the confirmation page
     */
    protected function createConfirmationHTML($header, $text, $contact)
    {
        $html = "<!doctype html>
        <html lang='en'>
          <head>
            <!-- Required meta tags -->
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
        
            <!-- Bootstrap CSS -->
            <link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css' integrity='sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm' crossorigin='anonymous'>
        
            <title>GDPR removal request</title>
            <style>
            body {
                padding-top: 5rem;
              }
              .gdpr {
                padding: 3rem 1.5rem;
                text-align: center;
              }

            </style>
          </head>
          <body>
          <main role='main' class='container'>

          <div class='gdpr'>
            <h1>$header</h1>
            <p class='lead'>$text</p>
            <a href='mailto://$contact' class=''>$contact</a>
            
          </div>
    
        </main><!-- /.container -->

           </body>
        </html>";
        return $html;

    }

    /*
     * Create the HTML for the error page
     */
    protected function createErrorHTML($contact)
    {
        $html = "<!doctype html>
        <html lang='en'>
          <head>
            <!-- Required meta tags -->
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
        
            <!-- Bootstrap CSS -->
            <link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css' integrity='sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm' crossorigin='anonymous'>
        
            <title>GDPR removal request</title>
            <style>
            body {
                padding-top: 5rem;
              }
              .gdpr {
                padding: 3rem 1.5rem;
                text-align: center;
              }

            </style>
          </head>
          <body>
          <main role='main' class='container'>

          <div class='gdpr'>
            <h1>Something went wrong</h1>
            <p class='lead'>Sorry, we could not automaticaly delete your data, please contact the e-mailadress below to remove your data</p>
            <a href='mailto://$contact' class=''>$contact</a>
            
          </div>
    
        </main><!-- /.container -->

           </body>
        </html>";
        return $html;
    }
}