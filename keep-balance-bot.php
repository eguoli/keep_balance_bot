<?php

// send default headers
header("HTTP/1.1 200 OK");
header('Content-type: text/html; charset=utf-8');

// create bot object
$bot = new KeepBot();
// init the bot
$bot->init();

/**
 * Class KeepBot
 */
class KeepBot
{
    // set the variables
    
    // bot API token
    private $token = "";

    // admin Telegram ID
    private $admin = 0;

    // database connection
    private $host = "";
    private $db = "";
    private $user = "";
    private $pass = "";    
    private $prefix = "bot_";
    private $charset = 'utf8mb4';

    // set strings
    private $errorText = "Incorrect input";

    /**
     * @var PDO
     */
    private $pdo;

    //////////////////////////////////
    // Launch the bot
    //////////////////////////////////
    /** Init
     * @return bool
     */
    public function init()
    {
        // create database connection
        $this->setPdo();
        // get the data and convert to array
        $rawData = json_decode(file_get_contents('php://input'), true);
        // send the data to the router
        $this->router($rawData);
        // return true to Telegram API
        return true;
    }

    /** Router
     * @param $data
     * @return bool
     */
    private function router($data)
    {
        // get buttons
        $buttons = $this->buildKeyboardButtons();

        // retrieve user ID and text
        $chat_id = $this->getChatId($data);
        $text = $this->getText($data);

        // message data
        if (array_key_exists("message", $data)) {
            // get admin action from db
            $action = $this->getAdminAction();
            // get user action from db
            $actionUser = $this->getUserAction($chat_id);
            // get user action from keyboard
            $actionKeyboard = $this->getKeyboardAction($text);

            // text type
            if (array_key_exists("text", $data['message'])) {
                // start command
                if ($text == "/start") {
                    $this->startBot($chat_id, $data);
                } elseif ($text == "/admin" && $this->isAdmin($chat_id)) {
                    // admin command for admin user
                    $this->adminPage();
                } elseif ($text == "/adminhelp" && $this->isAdmin($chat_id)) {
                    // admin help command
                    $this->adminHelp();
                } else { // other text messages
                    if (preg_match("~^addhelp_~", $action) && $this->isAdmin($chat_id)) {
                        // send new help content
                        $this->updateHelp($text);
                    } elseif ($actionKeyboard == "back") {
                        $this->startBot($chat_id, $data);
                    } elseif ($actionKeyboard == "balance") {
                        $this->showBalance($data);
                    } elseif ($actionKeyboard == "create") {
                        $this->showBalance($data);
                    } elseif ($actionKeyboard == "edit") {
                        $this->showBalance($data);
                    } elseif ($actionKeyboard == "delete") {
                        $this->showBalance($data);
                    } elseif ($actionKeyboard == "portfolio") {
                        $this->showPortfolio($data);
                    } elseif (in_array($actionKeyboard, array_keys($buttons))) {
                        // trigger for keyboard action
                        $this->showInfo($chat_id, $this->prefix . $actionKeyboard);
                    } else {
                        // not expecting any other data - send error
                        $this->sendMessage($chat_id, $this->errorText);
                    }
                }
            } elseif (array_key_exists("photo", $data['message'])) {
                // not expecting any photo type data - send error
                $this->sendMessage($chat_id, $this->errorText);
            } else {
                // not expecting any other data - send error
                $this->sendMessage($chat_id, $this->errorText);
            }
        }
        elseif (array_key_exists("callback_query", $data)) {
            // get callback query
            $func_param = explode("_", $text);
            // define function as variable
            $func = $func_param[0];
            // call the function and pass data
            $this->$func($data['callback_query']);
        }
        else {
            // not expecting any other data - send error
            $this->sendMessage($chat_id, $this->errorText);
        }
        return true;
    }

    //////////////////////////////////
    // Main methods
    //////////////////////////////////

    /** First message for new user
     * @param $chat_id
     */
    private function startBot($chat_id, $data)
    {
        // read from database
        $user = $this->pdo->prepare("SELECT * FROM bot_user WHERE user_id = :user_id");
        $user->execute(['user_id' => $chat_id]);
        // save new user to database
        if ($user->rowCount() == 0) {
            // create new record
            $newUser = $this->pdo->prepare("INSERT INTO bot_user SET user_id = :user_id, first_name = :first_name, last_name = :last_name, phone = :phone, adress = :adress, action = 'start'");
            $newUser->execute([
                'user_id' => $chat_id,
                'first_name' => $data['message']['chat']['first_name'],
                'last_name' => $data['message']['chat']['last_name'],
                'phone' => '',
                'adress' => '',
            ]);
        } else {
            // update user action
            @$this->setActionUser("start", $chat_id);
        }
        // prepare hello message
        $text = $this->prepareHello()[0];

        // check if user is admin
        if ($this->isAdmin($chat_id)) {
            $text .= "\n\n/admin";
        }
        $buttons = NULL;
        // send message
        $this->sendMessage($chat_id, $text, $buttons, 0);
    }

    /** Update help page action
     * @param $data
     */
    private function adminHelp()
    {
        if ($this->setActionAdmin("addhelp_0")) {
            // prepare data
            $text = "<b>Current version:</b>\n";
            $text .= $this->prepareHelp()[0] . "\n\n";
            $text .= "Please enter new Help message:";
            // prepare message data array
            $fields = [
                'chat_id' => $this->admin,
                'text' => $text,
                'parse_mode' => 'html',
            ];
            // send message data
            $this->botApiQuery("sendMessage", $fields);
        } else {
            $this->notice($data['id'], "Error occured while updating.");
        }
    }

    /** Update help page
     * @param $text
     */
    private function updateHelp($text)
    {
        // update database query
        $update = $this->pdo->prepare("UPDATE bot_help SET description = :description WHERE id = :id");
        // show page if succeed
        if ($update->execute(['id' => 1, 'description' => $text])) {
            // clear admin action
            $this->adminActionCancel();
            // show admin page
            $this->adminPage();
        } else {
            $this->sendMessage($this->admin, "Error occured while updating. \n/admin");
        }
    }

    /** Display Info page
     * @param $data
     */
    private function showInfo($chat_id, $page)
    {
        // prepare text
        $query = "SELECT * FROM $page ORDER BY id DESC LIMIT 1";
        $data = $this->pdo->query($query);
        // fetch data
        $item = $data->fetch();
        // return value
        $text = $item['description'];
        // send message
        $this->sendMessage($chat_id, $text);
    }

    /** Display Balance page
     * @param $data
     */
    private function showBalance($data)
    {

        // retrieve user ID and text
        $chat_id = $this->getChatId($data);
        $text = $this->getText($data);

        // prepare message
        $text = "Your Balance:\n\nKEEP = 0 KEEP\ntBTC = 0 tBTC\n\nin BTC:\n\nKEEP = 0 BTC\ntBTC = 0 BTC\nTotal = 0 BTC\n\nin USD:\n\nKEEP = 0 USD\ntBTC = 0 USD\nTotal = 0 USD\n\n";

        $buttons = NULL;
        // send message
        $this->sendMessage($chat_id, $text, $buttons, 2);
    }

    /** Display Portfolio page
     * @param $data
     */
    private function showPortfolio($data)
    {

        // retrieve user ID and text
        $chat_id = $this->getChatId($data);
        $text = $this->getText($data);

        // prepare message
        $text = "Please select the next action from the menu below ⬇️";

        $buttons = NULL;
        // send message
        $this->sendMessage($chat_id, $text, $buttons, 1);
    }

    /** Get data for Hello page
     * @return array
     */
    private function prepareHello()
    {
        // get data
        $data = $this->pdo->query("SELECT * FROM bot_hello ORDER BY id DESC LIMIT 1");
        // fetch data
        $item = $data->fetch();
        // return result
        return [$item['description'], $item['id']];
    }

    /** Get data for Help page
     * @return array
     */
    private function prepareHelp()
    {
        // get data
        $data = $this->pdo->query("SELECT * FROM bot_help ORDER BY id DESC LIMIT 1");
        // fetch data
        $item = $data->fetch();
        // return result
        return [$item['description'], $item['id']];
    }

    /** Get admin action from database
     * @return bool
     */
    private function getAdminAction()
    {
        // get data
        $data = $this->pdo->query("SELECT name FROM bot_admin ORDER BY id DESC LIMIT 1");
        // fetch data
        $lastAction = $data->fetch();
        // return acton name or false
        return isset($lastAction['name']) ? $lastAction['name'] : false;
    }

    /** Save admin action to database
     * @param $action
     * @return mixed
     */
    private function setActionAdmin($action)
    {
        // cancel all admin actions
        if ($this->adminActionCancel()) {
            // prepare query
            $insert = $this->pdo->prepare("INSERT INTO bot_admin SET name = :name");
            // return result
            return $insert->execute(['name' => $action]);
        } else {
            // return error
            $this->sendMessage($this->admin, "Error occured while updating.");
        }
    }

    /** Cancel all admin actions
     * @return mixed
     */
    private function adminActionCancel()
    {
        // return result
        return $this->pdo->query("DELETE FROM bot_admin");
    }

    /** Admin page
     *
     */
    private function adminPage()
    {
        // prepare
        $text = "Admin page\n   /adminhelp - Edit Help page";
        // show
        $this->sendMessage($this->admin, $text);
    }

    /** Get user action from database
     * @return bool
     */
    private function getUserAction($user_id)
    {
        // get data
        $data = $this->pdo->prepare("SELECT action FROM bot_user WHERE user_id = :user_id");
        $data->execute(['user_id' => $user_id]);
        // fetch data
        $lastAction = $data->fetch();
        // return acton name or false
        return !empty($lastAction['action']) ? $lastAction['action'] : false;
    }

    /** Save user action to database
     * @param $action
     * @return mixed
     */
    private function setActionUser($action, $user_id)
    {
        // prepare query
        $insert = $this->pdo->prepare("UPDATE bot_user SET action = :action WHERE user_id = :user_id");
        // return result
        return $insert->execute(['action' => $action, 'user_id' => $user_id]);
    }

    /** Update user action
     * @param $param
     * @param $value
     * @param $user_id
     * @return bool
     */
    private function setParamUser($param, $value, $user_id)
    {
        // prepare query
        $insert = $this->pdo->prepare("UPDATE bot_user SET " . $param . " = :value WHERE user_id = :user_id");
        // return result
        return $insert->execute(['value' => $value, 'user_id' => $user_id]);
    }

    /** Get user action from keyboard
     * @return bool|string
     */
    private function getKeyboardAction($text)
    {
        // get buttons
        $buttons = $this->buildKeyboardButtons();

        // return value
        foreach($buttons as $key => $button) {
            if($text == $button) {
                return $key;
            }
        }
        return false;

    }


    //////////////////////////////////
    // Additional methods
    //////////////////////////////////

    /**
     *  Create database connection
     */
    private function setPdo()
    {
        // set main parameters
        $db = "mysql:host=$this->host;dbname=$this->db;charset=$this->charset";
        // options
        $opt = [
            // error mode
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // default fetch mode
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // disable emulation
            PDO::ATTR_EMULATE_PREPARES => false,
            // set collate
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        // save PDO object to $this->pdo
        $this->pdo = new PDO($db, $this->user, $this->pass, $opt);
    }

    /** Check if user is admin
     * @param $chat_id
     * @return bool
     */
    private function isAdmin($chat_id)
    {
        // return true or fasle
        return $chat_id == $this->admin;
    }

    /** Get chat id
     * @param $data
     * @return mixed
     */
    private function getChatId($data)
    {
        if ($this->getType($data) == "callback_query") {
            return $data['callback_query']['message']['chat']['id'];
        }
        return $data['message']['chat']['id'];
    }

    /** Get message id
     * @param $data
     * @return mixed
     */
    private function getMessageId($data)
    {
        if ($this->getType($data) == "callback_query") {
            return $data['callback_query']['message']['message_id'];
        }
        return $data['message']['message_id'];
    }

    /** Get message text
     * @return mixed
     */
    private function getText($data)
    {
        if ($this->getType($data) == "callback_query") {
            return $data['callback_query']['data'];
        }
        return $data['message']['text'];
    }

    /** Define message type
     * @param $data
     * @return bool|string
     */
    private function getType($data)
    {
        if (isset($data['callback_query'])) {
            return "callback_query";
        } elseif (isset($data['message']['text'])) {
            return "message";
        } elseif (isset($data['message']['photo'])) {
            return "photo";
        } else {
            return false;
        }
    }

    /** Prepare keyboard buttons
     * @return array
     */
    public function buildKeyboardButtons()
    {
        // Set buttons
        $buttons = array(
            'portfolio' => "🧰 Portfolio",
            'balance' => "💰 Balance",
            'create' => "➕ Create",
            'edit' => "✏️ Edit",
            'delete' => "❌ Delete",
            'back' => hex2bin('E2AC85') . " Return",
        );
        return $buttons;
    }

    /** Get keyboard button by key
     * @param $text
     * @return bool|string
     */
    public function buildGetKeyboardButton($key)
    {
        // get buttons
        $buttons = $this->buildKeyboardButtons();

        // get button text
        if(array_key_exists($key,$buttons)) {
            return $buttons[$key];
        }
        else {
            return false;
        }
    }

    /** Prepare inline button
     * @param $text
     * @param string $callback_data
     * @param string $url
     * @return array
     */
    public function buildInlineKeyboardButton($text, $callback_data = '', $url = '')
    {
        // prepare text
        $replyMarkup = [
            'text' => $text,
        ];
        // prepare parameters
        if ($url != '') {
            $replyMarkup['url'] = $url;
        } elseif ($callback_data != '') {
            $replyMarkup['callback_data'] = $callback_data;
        }
        // return button
        return $replyMarkup;
    }

    /** Prepare inline buttons keyboard
     * @param array $options
     * @return string
     */
    public function buildInlineKeyBoard($options = NULL, $keyboard_id = 0)
    {
        if (!is_null($options) && is_array($options)) {
            // prepare buttons
            $replyMarkup = [
                'inline_keyboard' => $options,
            ];
        }
        else {
            // get buttons
            $buttons = $this->buildKeyboardButtons();

            // prepare keyboard
            $keyboard = [
                '0' => [[$buttons['portfolio'], $buttons['balance']]],
                '1' => [[$buttons['create'], $buttons['edit']],[$buttons['delete'], $buttons['back']]],
                '2' => [[$buttons['back']]]
            ];

            // prepare keyboard array
            $replyMarkup = [
                'keyboard' =>  $keyboard[$keyboard_id],
                'resize_keyboard' => true, 
                'one_time_keyboard' => false
            ];
        }

        // convert to JSON object
        $encodedMarkup = json_encode($replyMarkup, true);
        // return keyboard
        return $encodedMarkup;
    }

    /** Prepare button with parameters
     * @param $text
     * @param bool $request_contact
     * @param bool $request_location
     * @return array
     */
    public function buildKeyboardButton($text, $request_contact = false, $request_location = false)
    {
        $replyMarkup = [
            'text' => $text,
            'request_contact' => $request_contact,
            'request_location' => $request_location,
        ];
        return $replyMarkup;
    }

    /** Prepare keyboard
     * @param array $options
     * @param bool $onetime
     * @param bool $resize
     * @param bool $selective
     * @return string
     */
    public function buildKeyBoard(array $options, $onetime = false, $resize = false, $selective = true)
    {
        $replyMarkup = [
            'keyboard' => $options,
            'one_time_keyboard' => $onetime,
            'resize_keyboard' => $resize,
            'selective' => $selective,
        ];
        $encodedMarkup = json_encode($replyMarkup, true);
        return $encodedMarkup;
    }

    //////////////////////////////////
    // Telegram Bot API
    //////////////////////////////////

    /** Send text message with inline buttons
     * @param $user_id
     * @param $text
     * @param null $buttons
     * @return mixed
     */
    private function sendMessage($user_id, $text, $buttons = NULL, $keyboard = 0)
    {
        // prepare message data
        $data_send = [
            'chat_id' => $user_id,
            'text' => $text,
            'parse_mode' => 'html'
        ];
        // append keyboard buttons
        if (!is_null($buttons) && is_array($buttons)) {
            $data_send['reply_markup'] = $this->buildInlineKeyBoard($buttons);
        }
        else {
            $data_send['reply_markup'] = $this->buildInlineKeyBoard($buttons, $keyboard);
        }
        // send text message
        return $this->botApiQuery("sendMessage", $data_send);
    }

    /** Edit text mesage
     * @param $user_id
     * @param $message_id
     * @param $text
     * @param null $buttons
     * @return mixed
     */
    private function editMessageText($user_id, $message_id, $text, $buttons = NULL)
    {
        // prepare message data
        $data_send = [
            'chat_id' => $user_id,
            'text' => $text,
            'message_id' => $message_id,
            'parse_mode' => 'html'
        ];
        // append keyboard buttons
        if (!is_null($buttons) && is_array($buttons)) {
            $data_send['reply_markup'] = $this->buildInlineKeyBoard($buttons);
        }
        // send text message
        return $this->botApiQuery("editMessageText", $data_send);
    }


    /** Send notice message
     * @param $cbq_id
     * @param $text
     * @param bool $type
     */
    private function notice($cbq_id, $text = "", $type = false)
    {
        $data = [
            'callback_query_id' => $cbq_id,
            'show_alert' => $type,
        ];

        if (!empty($text)) {
            $data['text'] = $text;
        }

        $this->botApiQuery("answerCallbackQuery", $data);
    }

    /** Telegram Bot API query
     * @param $method
     * @param array $fields
     * @return mixed
     */
    private function botApiQuery($method, $fields = array())
    {
        $ch = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        curl_setopt_array($ch, array(
            CURLOPT_POST => count($fields),
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 10
        ));
        $r = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $r;
    }
}