<?php
declare(strict_types=1);

use App\Modules\AdvancedBacktesting\AdvancedBacktestingModule;
use App\Modules\AI\AIModule;
use App\Modules\Backtesting\BacktestingModule;
use App\Modules\Calendar\CalendarModule;
use App\Modules\Core\CoreModule;
use App\Modules\Learning\LearningModule;
use App\Modules\MarketIntelligence\MarketIntelligenceModule;
use App\Modules\ModuleRegistry;
use App\Modules\MultiTimeframe\MultiTimeframeModule;
use App\Modules\News\NewsModule;
use App\Modules\Reports\ReportsModule;
use App\Modules\RiskManagement\RiskManagementModule;
use App\Modules\Scanner\ScannerModule;
use App\Modules\SmartMoney\SmartMoneyModule;
use App\Modules\TradeCoach\TradeCoachModule;
use App\Modules\TradeJournal\TradeJournalModule;
use App\Modules\Vision\VisionModule;

ModuleRegistry::register(new CoreModule());
ModuleRegistry::register(new ScannerModule());
ModuleRegistry::register(new CalendarModule());
ModuleRegistry::register(new NewsModule());
ModuleRegistry::register(new BacktestingModule());
ModuleRegistry::register(new ReportsModule());
ModuleRegistry::register(new AIModule());
ModuleRegistry::register(new VisionModule());
ModuleRegistry::register(new TradeCoachModule());
ModuleRegistry::register(new LearningModule());
ModuleRegistry::register(new MultiTimeframeModule());
ModuleRegistry::register(new RiskManagementModule());
ModuleRegistry::register(new TradeJournalModule());
ModuleRegistry::register(new MarketIntelligenceModule());
ModuleRegistry::register(new SmartMoneyModule());
ModuleRegistry::register(new AdvancedBacktestingModule());
