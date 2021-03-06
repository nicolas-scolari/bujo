<?php

namespace App\Controller;

use App\Entity\ToDoList;
use App\Form\ToDoListType;
use App\Repository\ToDoListRepository;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Constraints\Date;

/**
 * @Route("/bujo/todolist")
 */
class ToDoListController extends AbstractController
{
    public function __construct(Security $security) 
    {
        $this->security = $security;
        $this->user = $this->getCurrentUserId();
    }

    /**
     * @Route("/", name="todolist.index", methods={"GET"})
     * Envoie la To Do List d'aujourd'hui
     */
    public function index(Request $request, ToDoListRepository $toDoListRepository): Response
    {

        //Je cherche la liste du jour
        $today = date('Y-m-d');
        $user = $this->getCurrentUserId();
        $todayList = $toDoListRepository->findByDate($today, $this->user);

        $today = new DateTime($today);
        return $this->loadForms($todayList, $request, $today, 'index', 'index');
        
    }

    /**
     * @Route("/previous", name="todolist.previous", options={"expose"=true}, methods={"GET", "POST"})
     * Ajax - envoie la To Do List précédente (peut être renouvelé)
     */
    public function previousList(Request $request, ToDoListRepository $toDoListRepository) {
        $date = $request->request->get('date');
        $list = $toDoListRepository->findByDate($date, $this->user);

        $dateFormatted = new DateTime($date);
        $dateFormatted->format('d m Y');

        return $this->loadForms($list, $request, $dateFormatted, 'edit', '_form');
    }

    /**
     * @Route("/new", name="todolist.new", methods={"GET","POST"}, options={"expose"=true})
     * Création d'une nouvelle tâche dans la To Do List d'aujourd'hui seulement
     */
    public function new(Request $request): Response
    {
        $toDoList = new ToDoList();
        $form = $this->createFormBuilder($toDoList, array(
            'action'=> $this->generateUrl('todolist.new'),
            'method' => 'POST',
            ))
            ->add('name', TextType::class, [
                'label' => "Nom : ",
                'attr' => [
                    'placeholder' => 'Écrire ici...'
                ]
            ])
            ->add('done', CheckboxType::class, [
                'label' => 'Fait : ',
                'required' => false
            ])
            ->getForm();
    
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();

            $today = new \DateTime();
            $today->format('d-m-Y');
            $toDoList->setDate($today);

            $user = $this->security->getUser();
            $toDoList->setUser($user);

            $entityManager->persist($toDoList);
            $entityManager->flush();

            return $this->redirectToRoute('todolist.index');
        }

        return $this->render('to_do_list/new.html.twig', [
            'to_do_list' => $toDoList,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}/edit", name="todolist.edit", methods={"GET","POST"})
     * Modification d'une entrée de la To Do List (une entrée à la fois seulement)
     */
    public function edit($id, Request $request, ToDoList $toDoList, ToDoListRepository $toDoListRepo): Response
    {
        $date = $toDoList->getdate();
        $list = $toDoListRepo->findByDate($date, $this->user);

        return $this->loadForms($list, $request, $date, 'edit', 'indexRedirect');

    }

    /**
     * @Route("/{id}", requirements={"id"="\d+"}, name="todolist.delete")
     */
    public function delete(Request $request, ToDoList $toDoList): Response
    {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($toDoList);
            $entityManager->flush();

        return $this->redirectToRoute('todolist.index');
    }

    /**
     * Cette fonction permet de créer et gérer les To Do List avec des variables dynamiques
     */
    private function loadForms(array $list, Request $request, $date, string $formUrl, string $templateName) {

        if (! $list == null) {

            //Je créée un formulaire checkbox/input/save pour chaque entrée de la bdd
            for ($i = 0; $i < sizeof($list); $i++) {
                $formAction = $this->createFormAction($formUrl, $list[$i]);

                ${"form" . $i} = $this->createNewForm($i, $list, $request, $formAction);

                $forms[] = ${"form" . $i}->createView();

                $ids[] = $list[$i]->getId();
            }

            //Je créée la gestion et modification de chaque formulaire
            for ($i = 0; $i < sizeof($list); $i++) {
                if(${"form" . $i}->isSubmitted() && ${"form" . $i}->isValid()) {
                    $em = $this->getDoctrine()->getManager();
                    $em->flush();
                }
            }

            //Je formate la date pour le rendu HTML
            if (is_object($date)) {
    
                $date = $date->format('d m Y');
                
            } else {
                $this->formatDateTime($date);
            }
            
            if ($templateName == 'indexRedirect') {
                return $this->redirectToRoute('todolist.index');
            } else {
                return $this->render('to_do_list/' . $templateName . '.html.twig', [
                    'ids' => $ids,
                    'to_do_lists' => $list,
                    'forms' => $forms,
                    'date' => $date
                ]);
            }

        } else {

            return $this->render('to_do_list/'. $templateName .'.html.twig', [
                'error' => 'Appuyez sur le bouton + pour démarrer une To Do List !'
            ]);

        }
    }

    /**
     * Change le action="..." de la balise <form> en fonction de la route
     */
    private function createFormAction($formUrl, $list) {
        if($formUrl == "index") {
            $formAction = $this->generateUrl('todolist.' . $formUrl);
        } else if ($formUrl == "edit") {
            $id = $list->getId();
            $formAction = $this->generateUrl('todolist.' . $formUrl, ["id" => $id]);
        }

        return $formAction;
    }

    private function createNewForm($index, $list, $request, $formAction) {
        ${"form" . $index} = $this->get('form.factory')->createNamed("form" . $index, ToDoListType::class, $list[$index], 
                    array(
                        'action' => $formAction,
                        'method' => 'GET',
                        'csrf_protection' => false
                ));
        return ${"form" . $index}->handleRequest($request);
    }

    //Formate la date
    private function formatDateTime($date) {
        $formattedDate = new DateTime($date);
        $date = $formattedDate->format('d m Y');
    }

    //Récupère l'id de l'utilisateur en cours (connecté)
    private function getCurrentUserId() {
        return $user = $this->security->getUser()
            ->getId()
        ;
    }
}
