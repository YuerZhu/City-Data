<?php
//connect sql
$conn = new mysqli("localhost","root","");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

//create database
$db_selected = $conn->select_db('Info');
if (!$db_selected) {
    $createdb = "CREATE DATABASE IF NOT EXISTS Info";
    if ($conn->query($createdb) === FALSE) {
        echo "Error creating database: " . $conn->error;
    }
    
    //create tables
    $link = new mysqli("localhost","root","","Info");
    if ($link->connect_error) {
        die("Connection failed: " . $link->connect_error);
    }
    $create_countries = "
    CREATE TABLE IF NOT EXISTS Countries(
        CountryId INT UNSIGNED PRIMARY KEY,
        CountryName VARCHAR(30) NOT NULL,
        CountryCode VARCHAR(10) NOT NULL
    )
    ";
    if ($link->query($create_countries) === FALSE) {
        echo "Error creating table: " . $link->error;
    }
    
    $create_locations = "
    CREATE TABLE IF NOT EXISTS Locations(
        LocationId INT UNSIGNED PRIMARY KEY,
        CountryId INT UNSIGNED,
        LocationName VARCHAR(30) NOT NULL,
        Latitude VARCHAR(30) NOT NULL,
        Longitude VARCHAR(30) NOT NULL
    )
    ";
    if ($link->query($create_locations) === FALSE) {
        echo "Error creating table: " . $link->error;
    }
    
    // //set foreign key
    // $set_fkey = "
    // IF (OBJECT_ID('dbo.FK_ConstraintName', 'F') IS NULL)
    // BEGIN
    //     ALTER TABLE Locations
    //     ADD FOREIGN Locations (CountryId) REFERENCES Countries(CountryId);
    // END
    // ";
    // if ($link->query($set_fkey) === FALSE) {
    //     echo "Error setting foreign key: " . $link->error;
    // }
    
    //parse json
    $info_json = file_get_contents('./db/info.json');
    $countries = json_decode($info_json, true)['countries'];
    $locations = json_decode($info_json, true)['locations'];
    
    //prepare insert queries
    $country_insert = "INSERT INTO Countries (CountryId, CountryName, CountryCode) VALUES (?, ?, ?)";
    $location_insert = "INSERT INTO Locations (LocationId, CountryId, LocationName, Latitude, Longitude) VALUES (?, ?, ?, ?, ?)";
    
    //inserts
    foreach($countries as $country) {
        if($stmt = mysqli_prepare($link, $country_insert)){
            mysqli_stmt_bind_param($stmt, "iss", $CountryId, $CountryName, $CountryCode);
            $CountryId = $country['CountryId'];
            $CountryName = $country['CountryName'];
            $CountryCode = $country['CountryCode'];
            mysqli_stmt_execute($stmt);
        }
    }
    
    foreach($locations as $location) {
        if($stmt = mysqli_prepare($link, $location_insert)){
            mysqli_stmt_bind_param($stmt, "iisss", $LocationId, $CountryId, $LocationName, $Latitude, $Longitude);
            $LocationId = $location['LocationId'];
            $CountryId = $location['CountryId'];
            $LocationName = $location['LocationName'];
            $Latitude = $location['Latitude'];
            $Longitude = $location['Longitude'];
            mysqli_stmt_execute($stmt);
        }
    }
    mysqli_close($link);
}
//update USA
$link = new mysqli("localhost","root","","Info");
$update = "UPDATE Countries SET CountryName = 'United States' WHERE CountryId = 20";
$link->query($update);
//close sql
mysqli_close($conn);

//search
if (isset($_POST["searchquery"])) {
    $searchresult = get_search_results($_POST["searchquery"]);
}

function get_search_results($query) {
    $link = new mysqli("localhost","root","","Info");
    if ($link->connect_error) {
        die("Connection failed: " . $link->connect_error);
    }
    $stmt = $link->prepare("SELECT Countries.CountryName, Locations.LocationName, Locations.Latitude, Locations.Longitude 
                            FROM Countries INNER JOIN Locations ON Countries.CountryId = Locations.CountryId
                            WHERE Countries.CountryName = ? OR Countries.CountryCode = ? OR Locations.LocationName = ?");
    $stmt->bind_param("sss", $query, $query, $query);
    $stmt->execute();
    $count = 0;
    $out = '';
    if ($results = $stmt->get_result()){
        if (mysqli_num_rows($results) == 0) {
            return "Country Not Found :(";
        }
        while ($result = $results->fetch_assoc()) {
            if ($count === 0) {
                $out = $out."City Info In ".$result["CountryName"].':<br>';
            }
            $cityName = str_pad(("City Name: ".$result["LocationName"]),30);
            $lat = str_pad(("Latitude: ".$result["Latitude"]),30);
            $lon = str_pad(("Longitude: ".$result["Longitude"]),30);
            $out = $out.$cityName.$lat.$lon.'<br>';
            $count = 1;
        }
        return $out;
    }
    mysqli_close($link);
}

//insert 
if (isset($_POST["insertquery"])) {
    $query = $_POST["insertquery"];
    $code = get_LatLonCountry_and_Insert($query);
    $insertresult = $query.", ".$code." inserted successfully";
}

function get_LatLonCountry_and_Insert($LocationName) {
    //get lat lon country
    $service_url = "http://api.positionstack.com/v1/forward?access_key=d67328a9c252354c50fa346f697223c8&query=".$LocationName;
    $curl = curl_init($service_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $curl_response = curl_exec($curl);
    if ($curl_response === false) {
        $info = curl_getinfo($curl);
        curl_close($curl);
        die('error occured during curl exec. Additioanl info: ' . var_export($info));
    }
    curl_close($curl);
    $decoded = json_decode($curl_response,true);
    if (isset($decoded->response->status) && $decoded->response->status == 'ERROR') {
        die('error occured: ' . $decoded->response->errormessage);
    }
    $lat = strval($decoded["data"]["0"]["latitude"]);
    $lon = strval($decoded["data"]["0"]["longitude"]);
    $country = $decoded["data"]["0"]["country"];
    //insert
    $link = new mysqli("localhost","root","","Info");
    $serach_country_stmt =  $link->prepare("SELECT * FROM Countries WHERE CountryName = ?");
    $serach_country_stmt->bind_param("s",$country);
    $serach_country_stmt->execute();
    $result_array = $serach_country_stmt->get_result()->fetch_assoc();
    $countryId = $result_array["CountryId"];
    $countrycode  = $result_array["CountryCode"];

    $count = "SELECT COUNT(LocationId) AS count FROM Locations";
    $size = $link->query($count)->fetch_assoc()["count"];
    // echo $size;
    $locationId = intval($size) + 11;

    // echo "<br>";
    // echo $locationId;
    // echo $countryId;
    // echo $LocationName;
    // echo $lat;
    // echo $lon;
    // echo "<br>";

    $insert_stmt = $link->prepare("INSERT INTO Locations (LocationId, CountryId, LocationName, Latitude, Longitude) VALUES (?, ?, ?, ?, ?)");
    $insert_stmt->bind_param('iisss', $locationId, $countryId, $LocationName, $lat, $lon);
    $insert_stmt->execute();

    // $count = "SELECT COUNT(LocationId) AS count FROM Locations";
    // $size = $link->query($count)->fetch_assoc()["count"];
    // echo $size;
    $link->close();
    return $countrycode;
}

//remove
if (isset($_POST["removequery"])) {
    $removeresult = remove($_POST["removequery"]);
}

function remove($query) {
    $link = new mysqli("localhost","root","","Info");
    if ($link->connect_error) {
        die("Connection failed: " . $link->connect_error);
    }
    $stmt = $link->prepare("DELETE FROM Locations WHERE LocationName = ?");
    $stmt->bind_param("s", $query);
    $stmt->execute();
    if ($link->affected_rows > 0) {
        $removeresult = $query." removed successfully";
    } else {
        $removeresult = $query." not found";
    }   
    // $count2 = "SELECT COUNT(LocationId) AS count2 FROM Locations";
    // echo $link->query($count2)->fetch_assoc()["count2"];
    mysqli_close($link);
    return $removeresult;
}
?>

<html>
    <head>
        <title>
            City Database
        </title>
    </head>
    <body>
        <form action="" method="post">
            <p>Search by Country Names, Country Codes, or City Names</p>
            <input autocomplete="off" type="text" name="searchquery">
        </form>
        <form action="" method="post">
            <p>Insert a city by City Name</p>
            <input autocomplete="off" type="text" name="insertquery">
        </form>
        <form action="" method="post">
            <p>Remove a city by City Name</p>
            <input autocomplete="off" type="text" name="removequery">
        </form>
        <?php if (isset($searchresult)) { ?>
            <pre><?php echo $searchresult ?></pre>
        <?php } ?>
        <?php if (isset($insertresult)) { ?>
            <pre><?php echo $insertresult ?></pre>
        <?php } ?>
        <?php if (isset($removeresult)) { ?>
            <pre><?php echo $removeresult ?></pre>
        <?php } ?>
    </body>
</html>