<?php
/**
 * MIT licence
 * Version 1.0.0
 * Sjaak Priester, Amsterdam 21-06-2014 ... 21-11-2015.
 *
 * ActiveRecord with spatial attributes in Yii 2.0 framework
 *
 * @link https://github.com/sjaakp/yii2-spatial
 */

namespace sjaakp\spatial;

use yii\db\ActiveQuery as YiiActiveQuery;

class ActiveQuery extends YiiActiveQuery {

    /**
     * @param $from - string|array
     *      string: GeoJson representation of POINT
     *      array:  location in the form [ <lng>, <lat> ] (two floats)
     * @param $attribute - attribute name of POINT
     * @param $radius number - search radius in kilometers
     * @return $this - query that returns models that are near to $from
     *
     * Example usages:
     * $here = [4.9, 52.3];     // longitude and latitude of my place
     * $nearestModel = <model>::find()->nearest($here, <attributeName>, 100)->one();    // search radius is 100 km
     * $fiveNearestModels =  <model>::find()->nearest($here, <attributeName>, 100)->limit(5)->all();
     * $dataProvider = new ActiveDataProvider([ 'query' => <model>::find()->nearest($here, <attributeName>, 100) ]);
     *
     *
     * @link http://www.plumislandmedia.net/mysql/haversine-mysql-nearest-loc/
     * @link https://en.wikipedia.org/wiki/Haversine_formula
     */
    public function nearest($from, $attribute, $radius = 100)    {
        $lenPerDegree = 111.045;    // km per degree latitude; for miles, use 69.0

        if (is_string($from))   {
            $feat = SpatialHelper::jsonToGeom($from);
            if ($feat && $feat['type'] == 'Point') $from = $feat['coordinates'];
        }
        if (! is_array($from)) return $this;
        $lng = $from[0];
        $lat = $from[1];

        $dLat = $radius / $lenPerDegree;
        $dLng = $dLat / cos(deg2rad($lat));

        /** @var \yii\db\ActiveRecord $modelCls */
        $modelCls = $this->modelClass;

        $subQuery = $this->create($this)->from($modelCls::tableName())
            ->select([
                '*',
                '_lng' => "X({$attribute})",
                '_lat' => "Y({$attribute})",
            ])
            ->having([ 'between', '_lng', $lng - $dLng, $lng + $dLng ])
            ->andHaving([ 'between', '_lat', $lat - $dLat, $lat + $dLat ]);

        $this->from([$subQuery])
            ->select([
                '*',
//                '_d' => "SQRT(POW(_lng-:lg,2)+POW(_lat-:lt,2))*{$lenPerDeg}"    // Pythagoras
                '_d' => "{$lenPerDegree}*DEGREES(ACOS(COS(RADIANS(:lt))*COS(RADIANS(_lat))*COS(RADIANS(:lg)-RADIANS(_lng))+SIN(RADIANS(:lt))*SIN(RADIANS(_lat))))"     // Haversine
            ])
            ->params([
                ':lg' => $lng,
                ':lt' => $lat
            ])
            ->having([ '<', '_d', $radius ])
            ->orderBy([
                '_d' => SORT_ASC
            ]);

        $this->where = null;
        $this->limit = null;
        $this->offset = null;
        $this->distinct = null;
        $this->groupBy = null;
        $this->join = null;
        $this->union = null;

        return $this;
    }

    protected $_skipPrep = false;

    protected function queryScalar($selectExpression, $db)  {
        $this->_skipPrep = true;
        $r = parent::queryScalar($selectExpression, $db);
        $this->_skipPrep = false;
        return $r;
    }

    public function prepare($builder)    {
        if (! $this->_skipPrep) {   // skip in case of queryScalar; it's not needed, and we get an SQL error (duplicate column names)
            if (empty($this->select))   {
                $this->select('*');
                $this->allColumns();
            }
            else   {
                /** @var ActiveRecord $modelClass */
                $modelClass = $this->modelClass;
                $schema = $modelClass::getTableSchema();
                foreach ($this->select as $field)   {
                    if ($field == '*')  {
                        $this->allColumns();
                    }
                    else {
                        $column = $schema->getColumn($field);
                        if (ActiveRecord::isSpatial($column)) {
                            $this->addSelect(["AsText($field) AS $field"]);
                        }
                    }
                }
            }
        }
        return parent::prepare($builder);
    }

    protected function allColumns() {
        /** @var ActiveRecord $modelClass */
        $modelClass = $this->modelClass;
        $schema = $modelClass::getTableSchema();
        foreach ($schema->columns as $column)   {
            if (ActiveRecord::isSpatial($column)) {
                $field = $column->name;
                $this->addSelect(["AsText($field) AS $field"]);
            }
        }
    }
}