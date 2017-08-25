### Papara Ödeme Sistemi Entegrasyonu
#### *Woocommerce*

* `function __construct()`

  Papara_Payment class'ı için gerekli olan init değerlerini atar. Ödeme yönteminin hangi fonksiyonları destekleyeceği (iade eklentisi olup olmayacağı, ödeme sayfasının external bir sayfada olup olmayacağı gibi) bu kısımda belirlenir. Ayrıca ödeme yöntemi ile ilgili temel atamalar da bu kısımda yapılır.

  **Önemli:** Bu kısımda bulunan `add_action()` fonksiyonu wordpress'in özel yapısı olan hook sistemini çalıştırır. Bu sistem ile wordpress'in defualt fonksiyonlarının işleyişine yeni bir fonksiyon kancalanmış olur.

  ```php
  add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ));
  ```

  Örneğin, yukarıdaki fonksiyon built-in fonksiyon olan `woocommerce_update_options_payment_gateways` fonksiyonuna bir çağrı yapıldığında, user tarafından oluşturulmuş `process_admin_options` fonksiyonunu çağırır. Bu yapı daha sonra çeşitli otomatik çağrılarda fonksiyon çalıştırmak için kullanılmıştır.

* `function init_form_fields()`

  Bu fonksiyon ile admin sayfasında gösterilecek ve kaydı tutulması gereken seçenekler oluşturulmuş ve ekrana yazdırılmıştır.

* `function admin_options()`

  Admin sayfasında bulunan kısa açıklama için kullanılmıştır.

* `function receipt_page( $order_id )`

  Parametre olarak aldığı `$order_id` kullanıcının ödeme yap butonunu tıklaması ile fonksyona gönderilir. Ve ardından ilk olarak `generate_papara_form()` fonksiyonu çağrılır. Bu fonksiyonun başarılı olması durumunda ödeme kaydı oluşturulur ve `$response_data` JSON objesi return edilir, başarısız olması durumunda ise `NULL` değeri return edilir. Bu fonksiyondan daha sonra detaylı bir şekilde bahsedilecektir.

  `$response_data:`
  ```JSON
  {
  "data": {
    "merchantId": "123-4564-8484",
    "userId": "123-987-654",
    "paymentMethod": 0,
    "paymentMethodDescription": "Papara Account Balance",
    "referenceId": "Üye işyeri referans bilgisi",
    "orderDescription": "Kullanıcının ödeme sayfasında göreceği açıklama",
    "status": 0,
    "statusDescription": "Pending",
    "amount": 99.99,
    "currency": "TRY",
    "notificationUrl": "https://www.viresinnumeris.com/notification",
    "notificationDone": false,
    "redirectUrl": "https://www.viresinnumeris.com/userredirect",
    "merchantSecretKey": "",
    "paymentUrl": "www.papara.com/pid?6666-5555-ABCD",
    "returningRedirectUrl": "",
    "id": "6666-5555-ABCD",
    "createdAt": "2017-06-09T06:26:15.100Z"
  },
  "succeeded": true,
    "error": {
    "message": "var ise hata mesajı",
    "code": 0
    }
  }
  ```
  Eğer `$response_data` `NULL` döner ise `order_meta_data` içine custom olarak eklenen `error_code` ile gerekli hata bilgisi ekrana bastırılır. Eğer başarılı bir işlem sonucu alınmış işe kullanıcı ödeme yapmak için `paymentUrl`e yönlendirilir.

* `function generate_papara_form ( $order_id )`

  Parametre olarak `receipt_page` fonksiyonundan gönderilen `$order_id`'yi alır. Bu id wordpress tarafından otomatik olarak verilir.

  **Hatırlatma:** Daha sonra `$order_id` referenceId olarak Papara'ya gönderilecektir

  Bu fonksiyonda 3 ana kısım vardır.

     *  HTTP POST ile oluşturulan `$payload` objesi kullanılarak ödeme kaydı sorgusu yapılır ve dönen sonuç kaydedilir. (`$response`)
     *  Ödeme kaydı sonucunu doğrulama için HTTP GET isteği yapılır dönen sonuç kaydedilir. (`check_first_response`)
     *  Her iki istek ile gelen sonuçlar kontrol edilir. Eğer ikisi de doğru ise 1. adımda yapılan istek üzerine dönem JSON objesi return edilir. Eğer ikisinden biri başarısız sonuçlanırsa hata kodu ekrana bastırılmak üzere kaydedilir.

* `function check_papara_response ()`

  Bu fonksiyon ile amaç kullanıcı siteye geri yönlendirilmeden önce yapılan HTTP POST isteğini (IPN) kullanarak ödeme sonucunu kontrol etmek ve doğrulamaktır.

  `notificationUrl`'e yapılan POST isteği sonucu `$data` objesine kaydedilir.

  `$data`:
  ```JSON
  {
    "merchantId": "123-4564-8484",
    "userId": "123-987-654",
    "paymentMethod": 1,
    "paymentMethodDescription": "Credit/Debit Card",
    "referenceId": "Üye işyeri referans bilgisi",
    "orderDescription": "Kullanıcının ödeme sayfasında göreceği açıklama",
    "status": 1,
    "statusDescription": "Completed",    
    "amount": 99.99,
    "currency": "TRY",
    "notificationUrl": "https://www.viresinnumeris.com/notification",
    "notificationDone": false,
    "redirectUrl": "https://www.viresinnumeris.com/userredirect",
    "merchantSecretKey": "Üye işyeri panelinde bulunan secret key",
    "paymentUrl": "www.papara.com/pid?6666-5555-ABCD",
    "returningRedirectUrl": "",
    "id": "6666-5555-ABCD",
    "createdAt": "2017-06-09T06:26:15.100Z"
  }
  ```

  Ödeme kaydı ile ilgili bilginin doğruluğunun garanti edilmesi için birkaç doğrulama yöntemi kullanılmıştır.

  * HTTP GET methodu ile Papara API'sine istek yaparak ödeme sonucunun oluşup oluşmadığı kontrol edilmiştir. Bu doğrulama yönteminde IPN bildiriminde bir hata ya da gecikme olması durumu da göz önünde bulundurularak (ödeme sonucunun üye işyerine POST edilememesi ya da gecikmesi durumunda) diğer kontroller sağlanmamış da olsa GET isteği sonucunun başarılı olması durumunda `OK` dönülmüştür.
  * Üye işyeri panelinden alınan Secret Key ile admin panelinden girilen key karşılaştırılmıştır.
  * Papara ödeme sayfasında yapılan ödeme ile sepet tutarı karşılaştırılmıştır.
  * IPN ile gönderilen `status` bilgisi kontrol edilmiştir.

  Eğer bu yöntemlerin herhangi birinde hata çıkmaz ise IPN bildirimini durdurmak için `OK` dönüşü sağlanmıştır. Ayrıca hatalar durumunda ve başarılı sonuçlanmada `order_status` bilgisi güncellenmiştir.

* `function process_refund( $order_id, $amount = null, $reason = '')`

  Parametreleri wordpress panelinde bulunan iade butonuna tıklayınca otomatik olarak gönderilir.

  **Not:** `$reason` parametresi iade sebebini kullanıcıya bildirmek için kullanılabilir.

  ```php
  if ( $amount != $order->get_total()) {
            return new WP_Error('papara',__('Amount error: Need to refund: '.$order->get_total(),'papara'));
  }
  ```

  Bu kısımda Papara'nın kısmi iade yapmaması sebebiyle kontrol sağlayarak eğer üye işyeri kısmi iade yapmak isterse hata bildirimi yapılması sağlanmıştır.

  HTTP PUT isteği yapılarak iade işlemi tamamlanır.
  
  **Önemli:** Her işlemden sonra `$order->update_status` komutu ile üye işyerinin sipariş durumu takip edilebilmesi sağlanır.
  
Copyright (c) 2017 mhmmtucan All Rights Reserved.
