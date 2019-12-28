<?php
/**
 * © 2019 by Open Knowledge Belgium vzw/asbl
 * This will return information about the composition of an NMBS/SNCB train.
 *
 * fillDataRoot will fill the entire dataroot with data.
 */

require_once __DIR__ . '/Tools.php';
require_once __DIR__ . '/Stations.php';

class Composition
{
    public static function fillDataRoot($dataroot, CompositionRequest $request)
    {
        $dataroot->composition = self::scrapeComposition($request->getId(), $request->getLang(),
            $request->getShouldReturnRawData());
    }

    /**
     * Scrape the composition of a train from the NMBS trainmap web application.
     * @param string $vehicleId The id of the vehicle for which the train composition should be retrieved (Example: IC587, or 587).
     * @param string $language string The request language.
     * @param bool $returnAllData Whether or not ALL data should be returned, including unstable, unchecked raw source data.
     * @return TrainCompositionResult The iRail response data.
     * @throws Exception Thrown when a vehicle with the specified ID couldn't be found.
     */
    private static function scrapeComposition(string $vehicleId, string $language, bool $returnAllData): TrainCompositionResult
    {
        $vehicleId = self::extractTrainNumber($vehicleId);

        $nmbsCacheKey = self::getNmbsCacheKey($vehicleId);

        $data = Tools::getCachedObject($nmbsCacheKey);
        if ($data === false) {
            $data = self::getNmbsData($vehicleId, $language);

            if ($data == null) {
                throw new Exception('Could not find vehicle ' . $vehicleId, 404);
            }

            // This data is static. Cache depending on the "state" of the data.
            // We don't cache suport short trains. If it's only a locomotive, it's incorrect data, and we don't want to cache incorrect data too long.
            if ($data[0]->confirmedBy == "Planning" || count($data[0]->materialUnits) < 2) {
                // Planning data often lacks detail. Store it for 5 minutes
                Tools::setCachedObject($nmbsCacheKey, $data, 5 * 60);
            } else {
                // Confirmed data doesn't change and contains all details. This data dissapears after the train ride,
                // so cache it long enough so it doesn't dissapear instantly after the ride.
                Tools::setCachedObject($nmbsCacheKey, $data, 60 * 60 * 6);
            }
        } else {
            Tools::sendIrailCacheResponseHeader(true);
        }


        if ($data == null) {
            throw new Exception('Could not find vehicle ' . $vehicleId, 404);
        }

        // Build a result
        $result = new TrainCompositionResult;
        foreach ($data as $travelsegmentWithCompositionData) {
            $result->segment[] = self::parseOneSegmentWithCompositionData($travelsegmentWithCompositionData, $language,
                $returnAllData);
        }

        return $result;
    }

    private static function parseOneSegmentWithCompositionData($travelsegmentWithCompositionData, string $language, bool $returnAllData): TrainCompositionInSegment
    {
        $result = new TrainCompositionInSegment;
        $result->origin = stations::getStationFromID('00' . $travelsegmentWithCompositionData->ptCarFrom->uicCode,
            $language);
        $result->destination = stations::getStationFromID('00' . $travelsegmentWithCompositionData->ptCarTo->uicCode,
            $language);
        $result->composition = self::parseCompositionData($travelsegmentWithCompositionData, $returnAllData);

        // Set the left/right orientation on carriages. This can only be done by evaluating all carriages at the same time
        $result->composition = self::setCorrectDirectionForCarriages($result->composition);

        return $result;
    }

    private static function parseCompositionData($travelsegmentWithCompositionData, bool $returnAllData): TrainComposition
    {
        $result = new TrainComposition();
        $result->source = $travelsegmentWithCompositionData->confirmedBy;
        $result->unit = [];
        foreach ($travelsegmentWithCompositionData->materialUnits as $i => $compositionUnit) {
            $result->unit[] = self::parseCompositionUnit($compositionUnit, $returnAllData, $i);
        }
        return $result;
    }

    /**
     * Parse a train composition unit, typically one carriage or locomotive.
     * @param $rawCompositionUnit StdClass the raw composition unit data.
     * @param bool $returnAllData True if all source data should be printed, even the unstable fields.
     * @param int $position The index/position of this vehicle.
     * @return TrainCompositionUnit The parsed and cleaned TrainCompositionUnit.
     */
    private static function parseCompositionUnit($rawCompositionUnit, bool $returnAllData, int $position): TrainCompositionUnit
    {
        $compositionUnit = self::transformRawCompositionUnitToTrainCompositionUnit($rawCompositionUnit, $returnAllData);
        $compositionUnit->materialType = self::getMaterialType($rawCompositionUnit, $position);
        return $compositionUnit;
    }

    private static function getMaterialType($rawCompositionUnit, $position): RollingMaterialType
    {
        $materialType = new RollingMaterialType();
        $materialType->parent_type = "unknown";
        $materialType->sub_type = "unknown";
        $materialType->orientation = "LEFT";

        if (property_exists($rawCompositionUnit, "tractionType") && $rawCompositionUnit->tractionType == "AM/MR") {
            self::setAmMrMaterialType($materialType, $rawCompositionUnit, $position);
        } elseif (property_exists($rawCompositionUnit, "tractionType") && $rawCompositionUnit->tractionType == "HLE") {
            self::setHleMaterialType($materialType, $rawCompositionUnit);
        } elseif (property_exists($rawCompositionUnit, "tractionType") && $rawCompositionUnit->tractionType == "HV") {
            self::setHvMaterialType($materialType, $rawCompositionUnit);
        } elseif (strpos($rawCompositionUnit->materialSubTypeName, '_') !== false) {
            // Anything else, defaul fallback
            $materialType->parent_type = explode('_', $rawCompositionUnit->materialSubTypeName)[0];
            $materialType->sub_type = explode('_', $rawCompositionUnit->materialSubTypeName)[1];
        }

        return $materialType;
    }

    private static function transformRawCompositionUnitToTrainCompositionUnit($object, $returnAllData): TrainCompositionUnit
    {
        $trainCompositionUnit = new TrainCompositionUnit();
        $wellDefinedProperties = [
            'hasToilets' => false,
            'hasTables' => false,
            'hasBikeSection' => false,
            'hasSecondClassOutlets' => false,
            'hasFirstClassOutlets' => false,
            'hasHeating' => false,
            'hasAirco' => false,
            'hasPrmSection' => false, // Persons with Reduced Mobility
            'hasPriorityPlaces' => false,
            'materialNumber' => 0,
            'tractionType' => "unknown",
            'canPassToNextUnit' => false,
            'standingPlacesSecondClass' => 0,
            'standingPlacesFirstClass' => 0,
            'seatsCoupeSecondClass' => 0,
            'seatsCoupeFirstClass' => 0,
            'seatsSecondClass' => 0,
            'seatsFirstClass' => 0,
            'lengthInMeter' => 0,
            'tractionPosition' => 0,
            'hasSemiAutomaticInteriorDoors' => false,
            'hasLuggageSection' => false,
            'materialSubTypeName' => "unknown"
        ];
        foreach ($wellDefinedProperties as $propertyName => $defaultValue) {
            if (property_exists($object, $propertyName)) {
                $trainCompositionUnit->$propertyName = $object->$propertyName;
            } else {
                $trainCompositionUnit->$propertyName = $defaultValue;
            }
        }


        if ($returnAllData) {
            // If the user wants all data, copy everything over
            foreach (get_object_vars($object) as $propertyName => $value) {
                if (!key_exists($propertyName, $wellDefinedProperties)) {
                    $trainCompositionUnit->$propertyName = $value;
                    if ($value == null) {
                        // replace null by a default value to prevent errors in the printers.
                        $trainCompositionUnit->$propertyName = false;
                    }
                }
            }
        }

        return $trainCompositionUnit;
    }

    /**
     * @param string $vehicleId The vehicle ID, numeric only. IC1234 should be passed as '1234'.
     * @param string $language The request language.
     * @return array The response data, or null when no data was found.
     */
    private static function getNmbsData(string $vehicleId, string $language)
    {
        $request_options = [
            'referer' => 'http://api.irail.be/',
            'timeout' => '30',
            'useragent' => Tools::getUserAgent(),
        ];

        $ch = curl_init();
        $url = "https://trainmapjs.azureedge.net/data/composition/" . $vehicleId;

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $request_options['useragent']);
        curl_setopt($ch, CURLOPT_REFERER, $request_options['referer']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $request_options['timeout']);
        $response = curl_exec($ch);
        curl_close($ch);

        // Store the raw output to a file on disk, for debug purposes
        if (key_exists('debug', $_GET) && isset($_GET['debug'])) {
            file_put_contents('../storage/debug-composition-' . $vehicleId . '-' . $language . '-' . time() . '.log',
                $response);
        }

        return json_decode($response);
    }

    /**
     * Get a unique key to identify data in the in-memory cache which reduces the number of requests to the NMBS.
     * @param string $id The train id.
     * @return string The key for the cached data.
     */
    public static function getNmbsCacheKey(string $id): string
    {
        return 'NMBSComposition|' . $id;
    }

    /**
     * Guess the correct orientation for rolling stock. This way we only need to code this once, instead of every user for themselves.
     * - The carriages are looped over from left to right. The train drives to the left, so the first vehicle is in front.
     * - The default orientation is LEFT. This means a potential drivers cab is to the LEFT.
     * - The last carriage in a traction group has a RIGHT orientation. This means a potential drivers cab is to the RIGHT.
     *
     * @param TrainComposition $composition
     * @return TrainComposition
     */
    private static function setCorrectDirectionForCarriages(TrainComposition $composition): TrainComposition
    {
        $lastTractionGroup = $composition->unit[0]->tractionPosition;
        for ($i = 0; $i < count($composition->unit); $i++) {
            if ($composition->unit[$i]->tractionPosition > $lastTractionGroup) {
                $composition->unit[$i - 1]->materialType->orientation = "RIGHT"; // Switch orientation on the last vehicle in each traction group
            }
            $lastTractionGroup = $composition->unit[$i]->tractionPosition;
        }
        $composition->unit[count($composition->unit) - 1]->materialType->orientation = "RIGHT"; // Switch orientation on the last vehicle of the train
        return $composition;
    }

    /**
     * Handle the material type for AM/MR vehicles ( trains consisting of a type-specific number of motorized carriages which are always together, opposed to having a locomotive and unmotorized carriages).
     * @param RollingMaterialType $materialType
     * @param $rawCompositionUnit
     */
    private static function setAmMrMaterialType(RollingMaterialType $materialType, $rawCompositionUnit, int $position): void
    {
        // "materialSubTypeName": "AM80_c",
        // "parentMaterialSubTypeName": "AM80",
        if (property_exists($rawCompositionUnit, "parentMaterialTypeName")) {
            // Sub type might not be set yet when in planning.
            $materialType->parent_type = $rawCompositionUnit->parentMaterialTypeName;
            // NMBS doesn't know the subtype yet, but we can calculate this based on the position.
            self::calculateAmMrSubType($materialType, $position);
        } elseif (property_exists($rawCompositionUnit, "parentMaterialSubTypeName")) {
            $materialType->parent_type = $rawCompositionUnit->parentMaterialSubTypeName;
            //AM80
            if (property_exists($rawCompositionUnit, "materialSubTypeName")) { // Some AM08 might be missing a type
                $materialType->sub_type = explode('_', $rawCompositionUnit->materialSubTypeName)[1]; // C
            } else {
                // This data isn't available in the planning stage
                $materialType->sub_type = "";
            }
        } else {
            $materialType->parent_type = "Unknown AM/MR";
            $materialType->sub_type = "";
        }
    }


    /**
     * Handle Electric Locomotives (HLE XX).
     * @param RollingMaterialType $materialType
     * @param $rawCompositionUnit
     */
    private static function setHleMaterialType(RollingMaterialType $materialType, $rawCompositionUnit): void
    {
        // Electric locomotives
        if (property_exists($rawCompositionUnit, "materialSubTypeName")
            && strpos($rawCompositionUnit->materialSubTypeName, 'HLE') === 0) {
            $materialType->parent_type = substr($rawCompositionUnit->materialSubTypeName, 0, 5); //HLE27
            $materialType->sub_type = substr($rawCompositionUnit->materialSubTypeName, 5);
        } else {
            $materialType->parent_type = substr($rawCompositionUnit->materialTypeName, 0, 5); //HLE18
            $materialType->sub_type = substr($rawCompositionUnit->materialTypeName, 5);
        }
    }

    /**
     * Handle HV rolling stock (carriages which can be linked together however you want).
     * @param RollingMaterialType $materialType
     * @param $rawCompositionUnit
     * @param $matches
     * @return mixed
     */
    private static function setHvMaterialType(RollingMaterialType $materialType, $rawCompositionUnit)
    {
        // Separate carriages
        if (property_exists($rawCompositionUnit, 'materialSubTypeName')) {
            preg_match('/([A-Z]\d+)\s?(.*?)$/', $rawCompositionUnit->materialSubTypeName, $matches);
            $materialType->parent_type = $matches[1]; // M6, I11
            $materialType->sub_type = $matches[2]; // A, B, BDX, BUH, ...
        } else {
            // Some special cases, typically when data is missing
            if (property_exists($rawCompositionUnit, 'materialTypeName')) {
                $materialType->parent_type = $rawCompositionUnit->materialTypeName;
            } else {
                $materialType->parent_type = "unknown";
            }
            $materialType->sub_type = "unknown";
        }
    }

    /**
     * @param RollingMaterialType $materialType
     * @param int $position
     */
    private static function calculateAmMrSubType(RollingMaterialType $materialType, int $position): void
    {
// The NMBS data contains A for the first, B for the second, C for the third, ... carriage in an AM/MR/AR train.
        // We can "fix" their planning data for these types by setting A, B, C, ourselves.
        // We still communicate that this is unconfirmed data, so there isn't a problem if one of the trains has a wrong orientation.
        // Trains with 3 carriages:
        if (in_array($materialType->parent_type, ['AM62-66', 'AM62', 'AM66'])) {
            switch ($position % 3) {
                case 0:
                    $materialType->sub_type = "a";
                    break;
                case 1:
                    $materialType->sub_type = "b";
                    break;
            }
        }

        // Trains with 3 carriages:
        if (in_array($materialType->parent_type, ['AM08', 'AM96'])) {
            switch ($position % 3) {
                case 0:
                    $materialType->sub_type = "a";
                    break;
                case 1:
                    $materialType->sub_type = "b";
                    break;
                case 2:
                    $materialType->sub_type = "c";
                    break;
            }
        }

        // Trains with 4 carriages:
        if (in_array($materialType->parent_type, ['AM80'])) {
            switch ($position % 3) {
                case 0:
                    $materialType->sub_type = "a";
                    break;
                case 1:
                    $materialType->sub_type = "b";
                    break;
                case 2:
                    $materialType->sub_type = "c";
                    break;
                case 3:
                    $materialType->sub_type = "d";
                    break;
            }
        }
    }

    /**
     * @param string $vehicleId
     * @return string|string[]|null
     */
    private static function extractTrainNumber(string $vehicleId)
    {
        $vehicleId = strtoupper($vehicleId);
        // Handle S trains. For example, S5 3381 or S53381 should become 3381
        $vehicleId = preg_replace("/S[12]0 ?(\d{3,})/", "$1", $vehicleId); // S10, S20
        $vehicleId = preg_replace("/S3[234] ?(\d{3,})/", "$1", $vehicleId); // S32, S33, S34
        $vehicleId = preg_replace("/S4[1234] ?(\d{3,})/", "$1", $vehicleId); // S41, 42, 43, 44
        $vehicleId = preg_replace("/S5[123] ?(\d{3,})/", "$1", $vehicleId); // S51, 52, 53
        $vehicleId = preg_replace("/S6[1234] ?(\d{3,})/", "$1", $vehicleId); // S61, 62, 63, 64
        $vehicleId = preg_replace("/S81 ?(\d{3,})/", "$1", $vehicleId); // S81
        $vehicleId = preg_replace("/S[0-9] ?/", "", $vehicleId); // S1-S9
        $vehicleId = preg_replace("/[^0-9]/", "", $vehicleId);
        return $vehicleId;
    }
}
