<?php
/*
     All Emoncms code is released under the GNU Affero General Public License.
     See COPYRIGHT.txt and LICENSE.txt.

     ---------------------------------------------------------------------
     Emoncms - open source energy visualisation
     Part of the OpenEnergyMonitor project:
     http://openenergymonitor.org

*/

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

class SolarLocation {

    private $log;

    private $redis;
    private $mysqli;

    public function __construct($mysqli, $redis) {
        $this->log = new EmonLogger(__FILE__);
        $this->redis = $redis;
        $this->mysqli = $mysqli;
    }

    private function exist($id) {
        if ($this->redis) {
            if ($this->redis->exists("solar:location#$id")) {
                return true;
            }
        }
        $result = $this->mysqli->query("SELECT * FROM solar_location WHERE id = '$id'");
        if ($result->num_rows>0) {
            if ($this->redis) {
                while ($location = $result->fetch_array()) {
                    $this->add_redis($location);
                }
            }
            return true;
        }
        return false;
    }

    private function exists_name($userid, $name) {
        $userid = intval($userid);
        $id = -1;
        
        $stmt = $this->mysqli->prepare("SELECT id FROM solar_location WHERE userid=? AND name=?");
        $stmt->bind_param("is", $userid, $name);
        $stmt->execute();
        $stmt->bind_result($id);
        $result = $stmt->fetch();
        $stmt->close();
        
        if ($result && $id > 0) return $id; else return false;
    }

    private function exists_location($userid, $latitude, $longitude) {
        $userid = intval($userid);
        $id = -1;
        
        $stmt = $this->mysqli->prepare("SELECT id FROM solar_location WHERE userid=? AND latitude=? AND longitude=?");
        $stmt->bind_param("idd", $userid, $latitude, $longitude);
        $stmt->execute();
        $stmt->bind_result($id);
        $result = $stmt->fetch();
        $stmt->close();
        
        if ($result && $id > 0) return $id; else return false;
    }

    public function create($userid, $location) {
        $userid = intval($userid);
        
        if (empty($location['name'])) {
            throw new SolarException("Location name is missing");
        }
        // TODO: implement, as soon as the name is manually configured via UI
        //else if ($this->exists_name($userid, $location['name'])) {
        //    throw new SolarException("Location name already exists: ".$location['name']);
        //}
        if (preg_replace('/[^\p{N}\p{L}\-\_\.\s]/u', '', $location['name']) != $location['name']) {
            throw new SolarException("Location name only contain a-z A-Z 0-9 - _ . and space or language specific characters");
        }
        
        if ((!isset($location['latitude']) && !is_numeric($location['latitude'])) ||
            (!isset($location['longitude']) && !is_numeric($location['longitude']))) {
            
            throw new SolarException("Locations longitude and latitude specification is incomplete or invalid");
        }
        
        // TODO: implement this, when manual location creation exists
        //else if ($this->exists_location($userid, $location['latitude'], $location['longitude'])) {
        //    throw new SolarException("Locations longitude and latitude already exist");
        //}
        
        if (!isset($location['altitude']) || $location['altitude'] == '') {
            $location['altitude'] = null;
        }
        else if (!is_numeric($location['altitude']) || $location['altitude'] < 0) {
            throw new SolarException("Locations altitude has to be a number above sea level");
        }
        if (!isset($location['albedo']) || $location['albedo'] == '') {
            $location['albedo'] = null;
        }
        else if (!is_numeric($location['albedo']) || $location['albedo'] < 0 || $location['albedo'] > 1) {
            throw new SolarException("Locations albedo has to be a number between 0 and 1");
        }
        
        $stmt = $this->mysqli->prepare("INSERT INTO solar_location (userid,name,albedo,latitude,longitude,altitude) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("isdddd",$userid,$location['name'],$location['albedo'],$location['latitude'],$location['longitude'],$location['altitude']);
        $stmt->execute();
        $stmt->close();
        
        $id = $this->mysqli->insert_id;
        if ($id < 1) {
            throw new SolarException("Unable to create location");
        }
        $location = array(
            'id' => $id,
            'userid' => $userid,
            'name' => $location['name'],
            'albedo' => $location['albedo'],
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'altitude' => $location['altitude']
        );
        if ($this->redis) {
            $this->add_redis($location);
        }
        return $this->parse($location);
    }

    public function get_list($userid) {
        if ($this->redis) {
            $locations = $this->get_list_redis($userid);
        } else {
            $locations = $this->get_list_mysql($userid);
        }
        return $locations;
    }

    private function get_list_mysql($userid) {
        $userid = intval($userid);
        
        $locations = array();
        $result = $this->mysqli->query("SELECT * FROM solar_location WHERE userid='$userid'");
        while ($location = $result->fetch_array()) {
            $locations[] = $this->parse($location);
        }
        return $locations;
    }

    private function get_list_redis($userid) {
        $userid = intval($userid);
        
        $locations = array();
        if ($this->redis->exists("solar:user#$userid:locations")) {
            foreach ($this->redis->sMembers("solar:user#$userid:locations") as $id) {
                $locations[] = $this->get_redis($id);
            }
        }
        else {
            $result = $this->mysqli->query("SELECT * FROM solar_location WHERE userid='$userid'");
            while ($location = $result->fetch_array()) {
                $this->add_redis($location);
                $locations[] = $this->parse($location);
            }
        }
        return $locations;
    }

    public function get($id) {
        $id = intval($id);
        if (!$this->exist($id)) {
            throw new SolarException("Location for id $id does not exist");
        }
        if ($this->redis) {
            // Get from redis cache
            return $this->get_redis($id);
        }
        else {
            // Get from mysql db
            $result = $this->mysqli->query("SELECT * FROM solar_location WHERE id = '$id'");
            return $this->parse($result->fetch_array());
        }
    }

    private function get_redis($id) {
        return $this->parse((array) $this->redis->hGetAll("solar:location#$id"));
    }

    private function add_redis($location) {
        $this->redis->sAdd("solar:user#".$location['userid'].":locations", $location['id']);
        $this->redis->hMSet("solar:location#".$location['id'], $location);
    }

    private function parse($location) {
        return array(
            'id' => intval($location['id']),
            'name' => $location['name'],
            'albedo' => floatval($location['albedo']),
            'latitude' => floatval($location['latitude']),
            'longitude' => floatval($location['longitude']),
            'altitude' => isset($location['altitude']) ? floatval($location['altitude']) : null
        );
    }

    public function update($location, $fields) {
        $id = intval($location['id']);
        //$fields = json_decode(stripslashes($fields), true);
        
        if (isset($fields['name'])) {
            $name = $fields['name'];
            
            if (preg_replace('/[^\p{N}\p{L}\-\_\.\s]/u', '', $name) != $name) {
                throw new SolarException("Location name only contain a-z A-Z 0-9 - _ . and space or language specific characters");
            }
            // TODO: implement, as soon as the name is manually configured via UI
            //else if ($this->exists_name($location['userid'], $name)) {
            //    throw new SolarException("Location name already exists: $name");
            //}
            $this->update_field($id, 's', 'name', $name);
        }
        $this->update_numeric($id, $fields, 'albedo');
        $this->update_numeric($id, $fields, 'latitude');
        $this->update_numeric($id, $fields, 'longitude');
        $this->update_numeric($id, $fields, 'altitude');
        
        return array('success'=>true, 'message'=>'Location successfully updated');
    }

    private function update_numeric($id, $fields, $field) {
        if (!array_key_exists($field, $fields)) {
            return;
        }
        $value = $fields[$field];
        
        if ($value && !is_numeric($value)) {
            throw new SolarException("Location $field has to be numeric");
        }
        $this->update_field($id, 'd', $field, $value);
    }

    private function update_field($id, $type, $field, $value) {
        if ($stmt = $this->mysqli->prepare("UPDATE solar_location SET $field = ? WHERE id = ?")) {
            $stmt->bind_param($type."i", $value, $id);
            if ($stmt->execute() === false) {
                $stmt->close();
                throw new SolarException("Error while update $field of location#$id");
            }
            $stmt->close();
            
            if ($this->redis) {
                $this->redis->hset("solar:location#$id", $field, $value);
            }
        }
        else {
            throw new SolarException("Error while setting up database update");
        }
    }

    public function delete($id) {
        $id = intval($id);
        if (!$this->exist($id)) {
            throw new SolarException("Location for id $id does not exist");
        }
        
        $this->mysqli->query("DELETE FROM solar_location WHERE `id` = '$id'");
        if ($this->redis) {
            $this->delete_redis($id);
        }
        return array('success'=>true, 'message'=>'Location successfully deleted');
    }

    private function delete_redis($id) {
        $userid = $this->redis->hget("solar:location#$id",'userid');
        $this->redis->del("solar:location#$id");
        $this->redis->srem("solar:user#$userid:locations", $id);
    }

}
