<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\ModelEvent;
use korcontrol\craftalgolia\models\Settings;
use korcontrol\craftalgolia\services\ClientService;
use korcontrol\craftalgolia\services\IndexingService;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'client' => [
                    'class' => ClientService::class,
                ],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@korcontrol/craftalgolia', __DIR__);

        $algoliaConfig = \Craft::$app->getConfig()->getConfigFromFile('algolia');

        $settings = new Settings();
        $settings->setAttributes($algoliaConfig, false);
        $this->set('client', new ClientService($settings));

        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) use ($algoliaConfig) {
                IndexingService::handleAfterSaveEvent($event, $algoliaConfig);
            }
        );

        Event::on(
            Element::class,
            Element::EVENT_AFTER_DELETE,
            static function (Event $event) {
                IndexingService::handleAfterDeleteEvent($event);
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
