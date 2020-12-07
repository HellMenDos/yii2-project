<?php

namespace app\models;

use Yii;
use app\models\queries\VacancyQuery;
use app\models\User as GlobalUser;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\conditions\AndCondition;
use yii\db\Expression;
use app\modules\bot\validators\LocationLatValidator;
use app\modules\bot\validators\LocationLonValidator;

/**
 * Class Vacancy
 *
 * @package app\models
 */
class Vacancy extends ActiveRecord
{
    public const STATUS_OFF = 0;
    public const STATUS_ON = 1;

    public const LIVE_DAYS = 30;

    public const REMOTE_OFF = 0;
    public const REMOTE_ON = 1;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%vacancy}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [
                [
                    'user_id',
                    'currency_id',
                    'name',
                    'requirements',
                    'conditions',
                    'responsibilities',
                ],
                'required',
            ],
            [
                [
                    'user_id',
                    'company_id',
                    'currency_id',
                    'status',
                    'gender_id',
                    'created_at',
                    'processed_at',
                ],
                'integer',
            ],
            [
                'location_lat',
                LocationLatValidator::class,
            ],
            [
                'location_lon',
                LocationLonValidator::class,
            ],
            [
                'max_hourly_rate',
                'double',
                'min' => 0,
                'max' => 99999999.99,
            ],
            [
                [
                    'name',
                ],
                'string',
                'max' => 255,
            ],
            [
                [
                    'requirements',
                    'conditions',
                    'responsibilities',
                ],
                'string',
                'max' => 10000,
            ],
        ];
    }

    /**
     * @return VacancyQuery|\yii\db\ActiveQuery
     */
    public static function find()
    {
        return new VacancyQuery(get_called_class());
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'max_hourly_rate' => Yii::t('bot', 'Max. hourly rate'),
            'remote_on' => Yii::t('bot', 'Remote work'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::className(),
                'updatedAtAttribute' => false,
            ],
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCurrency()
    {
        return $this->hasOne(Currency::class, ['id' => 'currency_id']);
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return $this->status == self::STATUS_ON;
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getMatches()
    {
        return $this->hasMany(Resume::className(), ['id' => 'resume_id'])
            ->viaTable('{{%job_vacancy_match}}', ['vacancy_id' => 'id']);
    }

    /**
     * @return queries\ResumeQuery
     */
    public function getMatchedResumes()
    {
        $query = Resume::find()
            ->live()
            ->matchLanguages($this)
            ->matchRadius($this)
            ->andWhere([
                '!=', Resume::tableName() . '.user_id', $this->user_id,
            ])
            ->groupBy(Resume::tableName() . '.id');

        return $query;
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getCounterMatches()
    {
        return $this->hasMany(Resume::className(), ['id' => 'resume_id'])
            ->viaTable('{{%job_resume_match}}', ['vacancy_id' => 'id']);
    }

    public function updateMatches()
    {
        $this->unlinkAll('matches', true);
        $this->unlinkAll('counterMatches', true);

        $resumesQuery = $this->getMatchedResumes();
        $resumesQueryNoRateQuery = clone $resumesQuery;
        $resumesQueryRateQuery = clone $resumesQuery;

        if ($this->max_hourly_rate) {
            $resumesQueryRateQuery->andWhere(new AndCondition([
                ['IS NOT', Resume::tableName() . '.min_hourly_rate', null],
                ['<=', Resume::tableName() . '.min_hourly_rate', $this->max_hourly_rate],
                [Resume::tableName() . '.currency_id' => $this->currency_id],
            ]));
            $resumesQueryNoRateQuery->andWhere(
                new AndCondition([
                    ['>', Resume::tableName() . '.min_hourly_rate', $this->max_hourly_rate],
                    ['<>', Resume::tableName() . '.currency_id', $this->currency_id],
                ])
            );

            foreach ($resumesQueryRateQuery->all() as $resume) {
                $this->link('matches', $resume);
                $this->link('counterMatches', $resume);
            }

            foreach ($resumesQueryNoRateQuery->all() as $resume) {
                $this->link('counterMatches', $resume);
            }
        } else {
            foreach ($resumesQueryRateQuery->all() as $resume) {
                $this->link('matches', $resume);
            }
        }
    }

    public function clearMatches()
    {
        if ($this->processed_at !== null) {
            $this->unlinkAll('matches', true);
            $this->unlinkAll('counterMatches', true);

            $this->setAttributes([
                'processed_at' => null,
            ]);

            $this->save();
        }
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getLanguagesRelation()
    {
        return $this->hasMany(Language::className(), ['id' => 'language_id'])
            ->viaTable('{{%vacancy_language}}', ['vacancy_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getVacancyLanguagesRelation()
    {
        return $this->hasMany(VacancyLanguage::class, ['vacancy_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getGlobalUser()
    {
        return $this->hasOne(GlobalUser::className(), ['id' => 'user_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getKeywordsRelation()
    {
        return $this->hasMany(JobKeyword::className(), ['id' => 'job_keyword_id'])
            ->viaTable('{{%job_vacancy_keyword}}', ['vacancy_id' => 'id']);
    }

    /**
     * {@inheritdoc}
     */
    public function afterSave($insert, $changedAttributes)
    {
        if (isset($changedAttributes['status'])) {
            if ($this->status == self::STATUS_OFF) {
                 $this->clearMatches();
            }
        }

        parent::afterSave($insert, $changedAttributes);
    }

    /**
     * @return array
     */
    public function notPossibleToChangeStatus()
    {
        $notFilledFields = [];

        if (!$this->getLanguagesRelation()->count()) {
            $notFilledFields[] = Yii::t('bot', $this->getAttributeLabel('languages'));
        }
        if ($this->remote_on == self::REMOTE_OFF) {
            if (!($this->location_lon && $this->location_lat)) {
                $notFilledFields[] = Yii::t('bot', $this->getAttributeLabel('location'));
            }
        }

        return $notFilledFields;
    }
}
