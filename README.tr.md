# OpenCart için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

OpenCart 4.0.2.0 ve sonrası için resmî Paymos ödeme eklentisi. Müşteri siparişi onaylar,
Paymos’un barındırılan ödeme sayfasına yönlendirilir ve projenizin etkinleştirdiği token ile
ağlardan dilediğiyle ödeme yapar — Tron, Ethereum, BSC, Solana ve desteklenen diğer ağlar.
Ardından OpenCart siparişiniz tarayıcının geri dönmesiyle değil, imzalı bir callback ile
kendiliğinden ilerler. Fatura siparişin kendi para biriminde düzenlenir ve kur, müşterinin ödeme
yaptığı anda sabitlenir.

Her şeyden önce bilmeye değer nokta: her ödeme durumunun *kendi* sipariş durumlarınızdan hangisini
yazacağına siz karar verirsiniz. Eklenti varsayılan değerlerle gelir ancak beş eşleştirmenin tamamı
kendi durum listenizden doldurulan açılır listelerdir; böylece Paymos yeni bir akış dayatmak
yerine mağazanızın hâlihazırda yürüttüğü iş akışına uyum sağlar.

[![İzleyin: 12 Resmî CMS Kripto Ödeme Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video anlatım (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- OpenCart 4.0.2.0 veya üzeri; eklenti 4.0.0.0–4.0.1.1 sürümlerine kurulmaz;
- `curl`, `hash`, `json` ve `openssl` uzantıları etkin PHP 8.0 veya üzeri;
- genel internetten erişilebilen HTTPS bir Mağaza URL’si (Store URL);
- bu mağazanın faturalandıracağı projenin panelde zaten açık olduğu bir Paymos hesabı.

Paymos PHP SDK paketin içine gömülüdür — Composer ile hiçbir şey kurmanız gerekmez. Yönetim ve
vitrin metinleri İngilizce, Rusça, Almanca, İspanyolca, Türkçe ve Basitleştirilmiş Çince olarak
gelir.

## Kurulum ve bağlantı

1. En güncel paketi [GitHub Releases](https://github.com/paymos-labs/opencart/releases/latest) üzerinden indirin.
2. **Extensions → Installer** ekranından `paymos.ocmod.zip` dosyasını yükleyin.
3. **Extensions → Extensions** yolunu açın, **Payments** kategorisini seçin ve Paymos’u kurun.
4. Eklentiyi düzenleyin ve Paymos panelinde hedef projeyi açın; o an seçili proje otomatik kullanılır.
5. Eklenti ayarlarında **Connect Paymos** düğmesine tıklayın.
6. Paymos ekranında gösterilen mağaza URL’sini ve mevcut projeyi onaylayın.

**Arşiv dosyasının adını değiştirmeyin.** OpenCart eklenti dizinini yüklenen dosya adından
türetir ve paketteki her denetleyici ad alanı (namespace) bu dizine göre oluşturulur. Yeniden
adlandırılan bir zip, içindeki hiçbir kodun adresleyemeyeceği bir klasöre kurulur ve eklenti
sessizce yüklenmez.

Sürüm herkes için tek bir dosyadır ve hiçbir kimlik bilgisi taşımaz: API anahtarı veya secret,
proje ID’si, webhook secret’ı, OAuth token’ı ya da cihaz kodu yoktur. 6. adım tamamlanana kadar
hiçbir şey etkinleşmez.

Onay hem Sandbox hem de Live ortamını birlikte hazırlar. Paymos her ortam için tek etkin Payment
anahtarınızı yeniden kullanır (yoksa yeni bir tane oluşturur) ve bu mağazaya bir Invoice webhook’u
yönlendirir — mevcut bir webhook yalnızca URL, kategori ve proje tamamen örtüştüğünde yeniden
kullanılır; aynı adresteki farklı bir webhook üzerine yazılmadan olduğu gibi bırakılır. Cihaz
yetkilendirmesi yalnızca bir teslimat kanalıdır; token’ı hızla sona erer, atılır ve Merchant API
çağrıları HMAC ile imzalanır.

## Secret saklama

Kimlik bilgileri ve geçici cihaz durumu, `config_encryption` değerinden türetilen anahtarla
AES-256-GCM şifreli bir OpenCart ayarı olarak saklanır. Kaydedilen secret’lar yönetim sayfasına
geri yazdırılmaz ve bunları kabul eden bir form alanı yoktur; formu kaydetmek mühürlü zarfa
dokunmadan ayarları korur. Bir anahtar veya webhook secret’ı rotasyona alındığında yeniden bağlanın.

## Ayarlar

| Ayar | Neyi kontrol eder |
|---|---|
| Status | Yöntemin ödeme adımında sunulup sunulmayacağı |
| Mode | Sandbox veya Live |
| Credentials | Salt okunur — mağazanın bağlı olup olmadığı |
| Webhook URL | Salt okunur — sizin için kaydedilir, test edebilmeniz için gösterilir |
| Title | Müşterinin ödeme adımında okuduğu yöntem adı |
| Button text | Barındırılan ödeme sayfasını açan düğmenin etiketi |
| Pending / Confirming / Paid / Failed / Cancelled status | Her ödeme durumu için seçtiğiniz sipariş durumu |
| Sort order | Diğer ödeme yöntemleri arasındaki sıralama |

OpenCart’ın dinlemek üzere kaydedildiği webhook adresi:

```text
https://your-store.example/index.php?route=extension/paymos/payment/paymos.callback
```

## Sipariş durumları

Sipariş OpenCart tarafından her zamanki gibi oluşturulur, *Awaiting Paymos payment* geçmiş satırı
eklenerek **Pending status** durumuna alınır ve müşteri barındırılan ödeme sayfasına gönderilir.
Buradan sonrasını callback yönetir.

| Ödeme durumu | Kullanılan durum | Temiz kurulumda varsayılan |
|---|---|---|
| Transfer görüldü, onay toplanıyor | Confirming status | Processing |
| Tutarın bir kısmı ulaştı veya sayılan transfer reorg ile geri alındı | Pending status | Pending |
| Fatura ödendi veya fazla ödendi | Paid status | Complete |
| Süre dolduğunda eksik ödeme | Failed status | Failed |
| Süresi doldu veya iptal edildi | Cancelled status | Canceled |

Her geçiş, Paymos faturasını adlandıran bir sipariş geçmiş satırı yazar; ödeme zincir üzerinde
kesinleştiğinde ödenmiş satır işlem hash’ini ve gezgin bağlantısını taşır.

İki davranış bu tablonun önüne geçer. Bir sipariş Paid status durumuna ulaştığında geç gelen veya
sırasız bir callback onu geri çekemez — eski durum geçmiş notu olarak kaydedilir ve yoksayılır.
Ayrıca tutarı artık siparişle uyuşmayan bir ödenmiş callback, siparişi tamamlamak yerine *needs
manual review* notuyla Confirming status durumuna geçirir; çünkü fatura oluşturulduktan sonra
değişen bir sipariş toplamı yazılım tarafından kendiliğinden kapatılmamalıdır.

## Sandbox’ta test etme

1. **Mode** ayarını Sandbox’ta bırakın, yöntemi etkinleştirin ve kendi vitrininizden bir sipariş verin.
2. Paymos panelinde o faturayı bulun ve istediğiniz sonucu tetikleyin — ödendi, fazla ödendi, eksik ödendi, iptal edildi. Sandbox’ta hiçbir şey zamanlayıcıyla onaylanmaz.
3. Siparişin geçmiş sekmesini kontrol edin: her olay eşlediğiniz durumla sırasıyla görünmelidir.
4. **Mode** ayarını Live konumuna alın. Her iki ortam da aynı bağlantıdan geldiği için fazladan kurulum gerekmez.

Sandbox ödemelerinin arkasında blokzincir yoktur; dolayısıyla ödenmiş satırda işlem hash’i veya
gezgin bağlantısı görünmez. Bu normaldir.

## Callback’ler ve mutabakat

Her teslimat, gönderilen tam baytlar üzerinden sabit zamanlı karşılaştırmayla doğrulanan ve
rotasyon sırasında her iki secret’ı da kabul eden `t={timestamp},v1={hmac_hex}` biçiminde
HMAC-SHA256 `X-Webhook-Signature` başlığı taşır. Olay ID’leri saklandığı için yeniden teslimat
siparişe tekrar uygulanmadan onaylanır.

Nihai bir callback’e asla yalnızca imzasıyla güvenilmez: sipariş ilerletilmeden önce fatura
Merchant API’den geri çekilir ve saklanan anlık görüntüyle karşılaştırılır.

Burada fatura mutabakatı bir cron görevi değil, bir düğmedir. Ayarlar sayfasının üstündeki
**Reconcile Paymos orders** düğmesi son 24 saat içindeki en fazla 50 sonuçlanmamış faturayı alır,
her birini API’den çeker, aynı eşleme ve korumalardan geçirir, bekleyen iadeleri mutabık kılar ve
kaç kaydı güncellediğini bildirir. Bir kesinti sonrasında ya da bir sipariş takılı kalmış
göründüğünde kullanın.

## İadeler

Eklenti, Paymos Merchant API (`/v1/refunds`) üzerinden sunucu tarafından hesaplanan iki modda
yerel iade desteği sunar: `full` (tam fatura iadesi) ve `overpayment` (`paid_over` faturadaki
fazla ödemenin iadesi). Fonlar yeni bir giden blokzincir işlemi olarak yalnızca Paymos’un ödenmiş
faturada kaydettiği doğrulanmış `first_sender` adresine iade edilir — elle alıcı cüzdan adresi
girilmez ve keyfî kısmi iade tutarları desteklenmez.

OpenCart’ta bir iade göndermek veya incelemek için:

1. OpenCart yönetim panelinde **Extensions → Extensions → Payments → Paymos** ekranını açın.
2. İade edilebilir tutarları, doğrulanmış `first_sender` adresini ve mevcut işlemleri yüklemek için **Paymos refunds** paneline (`refundPanel`) OpenCart sipariş ID’sini girin.
3. `full` veya `overpayment` iade eylemini seçin (`extension/paymos/payment/paymos.refund`).

Eklentiyi kurmak veya açmak 1 dakikalık bir OpenCart cron görevi kaydeder (`paymos_refunds` →
`extension/paymos/payment/paymos.refundCron`); ayrıca hem imzalı callback’ler hem de **Reconcile
Paymos orders** düğmesi bekleyen iade işlemlerini ilerletir. Tamamlanan iadeler Merchant API
üzerinden doğrulandıktan sonra OpenCart sipariş geçmişine kaydedilir ve Sandbox modu nihai
sonuçların (`completed`, `failed`, `cancelled`) simülasyonunu destekler.

## Sorun giderme

**Paymos kurulu kalmıyor.** Mağaza 4.0.2.0 öncesinde bir OpenCart sürümü çalıştırıyordur. Eklenti
kendini kaldırır, hiçbir tablo oluşturmaz ve OpenCart hata günlüğüne bulduğu sürümü belirten
`[Paymos] Installation refused` satırını yazar. OpenCart’ı güncelleyin ve Paymos’u yeniden kurun.

**Connect, mağazanın HTTPS üzerinden erişilebilir olması gerektiğini söylüyor.** Yeni kurulmuş bir
OpenCart’ta mağaza URL’si boş olabilir; bu durumda katalog URL’si kullanılır. O adres hâlâ http
ise bağlantı yarım bırakılmak yerine reddedilir. Mesaj bulduğu URL’yi gösterir — OpenCart
`config.php` dosyanıza https biçimini yazıp tekrar deneyin.

**Sipariş sonsuza kadar Pending durumunda kalıyor.** Yukarıdaki callback URL’sini ağınızın dışından
çağırın. HTTP kimlik doğrulaması ve yönlendirme olmadan yanıt vermelidir — yönlendirmeler takip
edilmez. Ardından OpenCart hata günlüğünde `[Paymos]` satırlarını kontrol edin.

**Ödeme adımı "Paymos is temporarily unavailable" hatası veriyor.** Fatura oluşturulamadı. Günlük
satırı nedeni belirtir; olağan sebepler bağlantısı tamamlanmamış bir ortam veya projenin
faturalandırmadığı bir sipariş para birimidir.

**Bir sipariş manuel inceleme gerektiriyor.** Fatura oluşturulduktan sonra ödeme ulaşmadan önce
sipariş toplamı değişmiştir. Geçmiş notu her iki tutarı da taşır — elle sonuçlandırın.

- [Dokümantasyon](https://paymos.io/tr/docs/cms-opencart)
- [Kaynak kodu](https://github.com/paymos-labs/opencart)
- [Değişiklik günlüğü](CHANGELOG.md)
- [Destek](mailto:support@paymos.io)
