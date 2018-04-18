<?php
namespace App\Controller\Payments;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Entity\Basket;
use Doctrine\Common\Persistence\ObjectManager;
use App\Payments\PaypalFactory;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\RedirectUrls;
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\PaymentExecution;

class PaypalController extends Controller
{
    private $basket;

    private $config;

    private $apiContext;

    public function __construct(ObjectManager $objectManager)
    {
        $this->basket = new Basket($objectManager);
        // TODO: Load the config in a cleaner way
        $this->config = require(__DIR__ . '/../../../config/paypal.php');
        $this->apiContext = new ApiContext(
            new OAuthTokenCredential(
                $this->config['client_id'],
                $this->config['secret'])
        );
    }

    /**
     * Generates the payment and redirects to the paypal checkout page
     *
     * @param Request $req
     * @return void
     */
    public function paypalCheckout(Request $req)
    {
        $payer = new Payer();
        $payer->setPaymentMethod("paypal");

        $baseUrl = $req->getScheme() . '://' . $req->getHttpHost();

        $redirectUrls = (new RedirectUrls())
            ->setReturnUrl($baseUrl . $this->generateUrl('paypal_payment'))
            ->setCancelUrl($baseUrl . $this->generateUrl('basket_show'));

        $payment = (new Payment())
            ->setPayer((new Payer())->setPaymentMethod('paypal'))
            ->setIntent('sale')
            ->addTransaction(PaypalFactory::create($this->basket))
            ->setRedirectUrls($redirectUrls);
        
        try
        {
            $payment->create($this->apiContext);
        }
        catch (\Exception $e)
        {
            return new Response('Payement impossible');
        }
        
        return $this->redirect($payment->getApprovalLink());
    }

    /**
     * Actually executes the payment after the customer was redirected back from paypal
     *
     * @param Request $req
     * @return void
     */
    public function paypalPayment(Request $req)
    {
        $payment = Payment::get($req->get('paymentId'), $this->apiContext);
        
        $execution = (new PaymentExecution())
            ->setPayerId($req->get('PayerID'))
            ->setTransactions($payment->getTransactions());
        
        try
        {
            $payment->execute($execution, $this->apiContext);
        }
        catch (\Exception $e)
        {
            return new Response('Payement impossible');
        }

        $this->addFlash('success', 'Merci pour votre achat');
        return $this->redirectToRoute('index');
    }
}
