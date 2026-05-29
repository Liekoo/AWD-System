<?php
session_start();

// This goes up one level to find config.php in your root htdocs folder
require_once '../config.php'; 
require_once '../env.php'; // Load environment variables

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // REPLACE THESE WITH ACTUAL GOOGLE CLOUD CREDENTIALS
    $client_id = $_ENV['GOOGLE_CLIENT_ID'];
    $client_secret = $_ENV['GOOGLE_CLIENT_SECRET'];
    $redirect_uri = $_ENV['GOOGLE_REDIRECT_URI'];

    // 1. Exchange the authorization code from Google for an Access Token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code' => $code,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ]));
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    // 2. If we successfully got the token, fetch the user's profile info
    if (isset($response['access_token'])) {
        $user_info = json_decode(file_get_contents("https://www.googleapis.com/oauth2/v1/userinfo?access_token=" . $response['access_token']), true);
        
        $email = $user_info['email'];
        $name = $user_info['name'];
        $google_id = $user_info['id'];

        // 3. Database Logic
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // User exists -> Log them in
            $user = $result->fetch_assoc();
            $_SESSION['user_id']   = $user['User_ID']; 
            $_SESSION['username']  = $user['Username'];
            $_SESSION['full_name'] = $user['Full_Name'];
            $_SESSION['role']      = $user['Role']; // This fixes the Warning!
        } else {
            // User doesn't exist -> Register them
            // Note: We include 'Full_Name' and set a default 'Role'
            $role = 'user';
            $status = 'active';
            $insert = $conn->prepare("INSERT INTO users (Full_Name, Username, email, google_id, Role, Status) VALUES (?, ?, ?, ?, ?, ?)");
            $insert->bind_param("ssssss", $name, $name, $email, $google_id, $role, $status);
            $insert->execute();
            
            // Set sessions for the new user
            $_SESSION['user_id']   = $conn->insert_id;
            $_SESSION['username']  = $name;
            $_SESSION['full_name'] = $name;
            $_SESSION['role']      = $role;
        }

        // 4. Redirect back to your root homepage
        header("Location: /index.php"); 
        exit;
        
    } else {
        // Helpful for debugging if Google rejects the token exchange
        echo "Error getting access token. Please verify your Client ID, Client Secret, and Redirect URI.";
        echo "<br>Google Response: " . print_r($response, true);
        exit;
    }
} else {
    // If someone visits this page directly without coming from Google, send them to login
    header("Location: /auth/login.php");
    exit;
}
?>